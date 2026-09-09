<?php

namespace XD\SilverstripeAI\Services;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use XD\SilverstripeAI\Models\AIRequestLog;
use SilverStripe\Core\Injector\Injector;
use Psr\Log\LoggerInterface;

class AIClient
{
    use Injectable;
    use Configurable;

    private static int $max_text_length = 5000;
    private static int $max_instructions_length = 1000;

    private static string $default_instructions = 'You are a helpful assistant and SEO expert.';

    /**
     * Best-effort hints: which model-name fragments are expected per platform type.
     * Only used to log a warning on an obvious AI_PLATFORM_TYPE / AI_MODEL mismatch.
     * Platform types not listed here (azure, vertex, openrouter, ollama, generic) use arbitrary
     * deployment/model/slug names and are never warned about.
     */
    private static array $platform_model_hints = [
        'openai'    => ['gpt', 'o1', 'o3', 'o4', 'chatgpt'],
        'anthropic' => ['claude'],
        'gemini'    => ['gemini', 'gemma'],
        'mistral'   => ['mistral', 'magistral', 'ministral', 'codestral', 'pixtral', 'devstral'],
    ];

    /**
     * Pricing per million tokens for each model: [input, output].
     * Costs are in USD per 1,000,000 tokens.
     */
    private static array $model_pricing = [
        'gpt-4o-mini'        => ['input' => 0.15,   'output' => 0.60],
        'gpt-4o'             => ['input' => 2.50,   'output' => 10.00],
        'gpt-3.5-turbo'      => ['input' => 0.50,   'output' => 1.50],
        'claude-opus-4-6'    => ['input' => 15.00,  'output' => 75.00],
        'claude-sonnet-4-6'  => ['input' => 3.00,   'output' => 15.00],
        'claude-haiku-4-5'   => ['input' => 0.25,   'output' => 1.25],
        'gemini-1.5-pro'     => ['input' => 1.25,   'output' => 5.00],
    ];

    /**
     * Generate a text response from a prompt.
     */
    public function generateText(string $text, string $instructions = ''): string
    {
        $messages = new MessageBag(
            Message::forSystem($this->resolveInstructions($instructions)),
            Message::ofUser($this->limit($text, 'max_text_length'))
        );

        return $this->invoke($messages, 'text');
    }

    /**
     * Translate a JSON payload of field values.
     *
     * Unlike generateText(), the input is NOT truncated — a translation must never silently drop content —
     * and no default (SEO) instructions are applied. The caller supplies the full system instruction and is
     * responsible for validating/decoding the returned string (models occasionally wrap JSON in fences).
     * Logged under the `translate` mode so translation spend is filterable in the AI Usage admin.
     */
    public function translateJson(string $json, string $instructions): string
    {
        $messages = new MessageBag(
            Message::forSystem($instructions),
            Message::ofUser($json)
        );

        return $this->invoke($messages, 'translate');
    }

    /**
     * Generate a keyed set of fields from context data.
     * Returns an associative array matching the requested field keys.
     */
    public function generateFields(array $context, array $fields, string $instructions = ''): array
    {
        $prompt = "Instructions:\n" . $this->resolveInstructions($instructions) . "\n\nContext:\n";

        foreach ($context as $key => $value) {
            $prompt .= "{$key}: " . $this->limit((string) $value, 'max_text_length') . "\n";
        }

        $prompt .= "\nReturn ONLY valid JSON with these keys:\n";
        $prompt .= implode("\n", $fields);

        $messages = new MessageBag(
            Message::forSystem('Return ONLY raw JSON. Do NOT use markdown. Do NOT wrap in ```.. No explanations.'),
            Message::ofUser($prompt)
        );

        $output = $this->invoke($messages, 'fields');

        // Strip markdown code fences if present
        $output = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($output));

        $decoded = json_decode($output, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('AI returned invalid JSON: ' . $output);
        }

        return $decoded;
    }

    protected function invoke(MessageBag $messages, string $mode = 'text'): string
    {
        $platformType = strtolower(Environment::getEnv('AI_PLATFORM_TYPE') ?: 'openai');
        $model        = Environment::getEnv('AI_MODEL') ?: 'gpt-4o-mini';
        $platform     = $this->getPlatform();

        $this->warnOnModelMismatch($platformType, $model);

        $response = $platform->invoke($model, $messages);

        // Resolve the result first: Symfony AI only promotes token_usage into the
        // result metadata during conversion (DeferredResult::getResult()), so the
        // metadata is empty until asText()/getResult() has run.
        $text = $response->asText();

        // Extract token usage via Symfony AI metadata
        $promptTokens     = null;
        $completionTokens = null;
        $totalTokens      = null;

        try {
            $usage = $response->getMetadata()->get('token_usage');
            if ($usage !== null) {
                $promptTokens = method_exists($usage, 'getPromptTokens')
                    ? $usage->getPromptTokens()
                    : ($usage->promptTokens ?? $usage->inputTokens ?? null);

                $completionTokens = method_exists($usage, 'getCompletionTokens')
                    ? $usage->getCompletionTokens()
                    : ($usage->completionTokens ?? $usage->outputTokens ?? null);

                // Prefer the provider-reported total (covers thinking/tool tokens);
                // AIRequestLog falls back to prompt + completion when it is null.
                $totalTokens = method_exists($usage, 'getTotalTokens')
                    ? $usage->getTotalTokens()
                    : ($usage->totalTokens ?? null);
            }
        } catch (\Throwable) {
            // Usage not available for this platform/version
        }

        // Fallback for bridges that don't surface token_usage metadata (the generic OpenAI-compatible
        // bridge and everything built on it: OpenRouter, Ollama, and Mistral's compat endpoint). Read the
        // "usage" block straight from the raw response body. Only runs when metadata gave us nothing, so
        // native bridges (OpenAI/Anthropic/Gemini/Vertex/Mistral) keep their richer metadata untouched.
        if ($promptTokens === null && $completionTokens === null && $totalTokens === null) {
            try {
                $data  = $response->getRawResult()?->getData();
                $usage = is_array($data) ? ($data['usage'] ?? null) : null;

                if (is_array($usage)) {
                    // Chat Completions shape (prompt_/completion_) with a Responses-API fallback (input_/output_).
                    $promptTokens     = $usage['prompt_tokens']     ?? $usage['input_tokens']  ?? null;
                    $completionTokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;
                    $totalTokens      = $usage['total_tokens']      ?? null;
                }
            } catch (\Throwable) {
                // Raw body unavailable (e.g. a streamed response) — leave usage null.
            }
        }

        $estimatedCost = $this->estimateCost($model, $promptTokens, $completionTokens);

        AIRequestLog::log($platformType, $model, $mode, $promptTokens, $completionTokens, $estimatedCost, $totalTokens);

        return $text;
    }

    protected function estimateCost(string $model, ?int $promptTokens, ?int $completionTokens): ?float
    {
        if ($promptTokens === null && $completionTokens === null) {
            return null;
        }

        $pricing = $this->config()->get('model_pricing');
        // Match the exact model, then fall back to the slug without its provider prefix so that
        // OpenRouter/generic slugs (e.g. "openai/gpt-4o-mini") resolve to the same rates as "gpt-4o-mini".
        $rates   = $pricing[$model]
            ?? $pricing[preg_replace('#^[^/]+/#', '', $model)]
            ?? null;

        if (!$rates) {
            return null;
        }

        return (($promptTokens ?? 0) / 1_000_000 * $rates['input'])
             + (($completionTokens ?? 0) / 1_000_000 * $rates['output']);
    }

    public static function isEnabled(): bool
    {
        return (bool)Environment::getEnv('AI_API_KEY');
    }

    protected function getPlatform()
    {
        $apiKey = Environment::getEnv('AI_API_KEY');

        if (!$apiKey) {
            throw new \RuntimeException('AI API key not configured');
        }

        $platformType = strtolower(Environment::getEnv('AI_PLATFORM_TYPE') ?: 'openai');

        // Optional OpenAI-compatible endpoint override. Used to reach in-region entry points
        // (e.g. OpenRouter EU: https://eu.openrouter.ai/api), self-hosted models, or any
        // OpenAI-compatible proxy. When unset, the direct providers keep their default endpoints.
        $baseUrl = Environment::getEnv('AI_PLATFORM_BASE_URL') ?: null;

        return match ($platformType) {
            'openai'              => \Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory::create($apiKey),
            'claude', 'anthropic' => \Symfony\AI\Platform\Bridge\Anthropic\PlatformFactory::create($apiKey),
            'gemini', 'google'    => \Symfony\AI\Platform\Bridge\Gemini\PlatformFactory::create($apiKey),

            // OpenRouter: the default US endpoint, or an in-region entry point (e.g.
            // https://eu.openrouter.ai/api for EU data residency) when AI_PLATFORM_BASE_URL is set.
            // Same key, same model slugs — OpenRouter is OpenAI-compatible, so the generic bridge
            // handles the regional base URL.
            'openrouter'          => $baseUrl
                ? \Symfony\AI\Platform\Bridge\Generic\PlatformFactory::create($baseUrl, $apiKey)
                : \Symfony\AI\Platform\Bridge\OpenRouter\PlatformFactory::create($apiKey),

            // Azure OpenAI is addressed by resource endpoint + deployment + api-version (not a bare key).
            // Endpoint via AI_PLATFORM_BASE_URL (e.g. https://my-resource.openai.azure.com).
            'azure'               => \Symfony\AI\Platform\Bridge\Azure\OpenAi\PlatformFactory::create(
                $this->requireEnv('AI_PLATFORM_BASE_URL', 'azure'),
                $this->requireEnv('AI_AZURE_DEPLOYMENT', 'azure'),
                Environment::getEnv('AI_AZURE_API_VERSION') ?: '2024-10-21',
                $apiKey
            ),

            // Vertex AI is addressed by location + project; the API key is optional (ADC is common).
            'vertex'              => \Symfony\AI\Platform\Bridge\VertexAi\PlatformFactory::create(
                $this->requireEnv('AI_VERTEX_LOCATION', 'vertex'),
                $this->requireEnv('AI_VERTEX_PROJECT', 'vertex'),
                $apiKey
            ),

            // Mistral (EU/FR): prefer the native Symfony bridge when symfony/ai-mistral-platform is
            // installed — it ships Mistral's model catalog and a token-usage extractor, so cost/usage is
            // logged. Without the package, fall back to Mistral's OpenAI-compatible EU endpoint via the
            // generic bridge (works, but the generic converter reports no token_usage). Override with
            // AI_PLATFORM_BASE_URL.
            'mistral'             => class_exists(\Symfony\AI\Platform\Bridge\Mistral\PlatformFactory::class)
                ? \Symfony\AI\Platform\Bridge\Mistral\PlatformFactory::create($apiKey)
                : \Symfony\AI\Platform\Bridge\Generic\PlatformFactory::create($baseUrl ?: 'https://api.mistral.ai', $apiKey),

            // Ollama (self-hosted) speaks the OpenAI-compatible API, so it runs through the generic
            // bridge — no extra package required. Defaults to a local Ollama; override with AI_PLATFORM_BASE_URL.
            'ollama'              => \Symfony\AI\Platform\Bridge\Generic\PlatformFactory::create(
                $baseUrl ?: 'http://localhost:11434',
                $apiKey
            ),

            // Any other OpenAI-compatible endpoint (self-hosted vLLM/LM Studio, an EU proxy, …).
            'generic'             => \Symfony\AI\Platform\Bridge\Generic\PlatformFactory::create(
                $this->requireEnv('AI_PLATFORM_BASE_URL', 'generic'),
                $apiKey
            ),

            default               => throw new \InvalidArgumentException("Unknown AI platform type: $platformType"),
        };
    }

    /**
     * Read a required environment variable for a platform, with a clear error when it is missing
     * (Azure/Vertex/generic need more than an API key).
     */
    protected function requireEnv(string $key, string $platformType): string
    {
        $value = Environment::getEnv($key);

        if ($value === false || $value === null || $value === '') {
            throw new \RuntimeException(sprintf(
                'AI platform "%s" requires the %s environment variable to be set.',
                $platformType,
                $key
            ));
        }

        return (string) $value;
    }

    /**
     * Log a warning when AI_MODEL clearly does not match AI_PLATFORM_TYPE
     * (e.g. a "claude-…" model configured against the OpenAI platform). Best-effort
     * and non-fatal; unknown platform types are skipped.
     */
    protected function warnOnModelMismatch(string $platformType, string $model): void
    {
        $hints = (array) $this->config()->get('platform_model_hints');

        if (!isset($hints[$platformType])) {
            return;
        }

        $needle = strtolower($model);
        foreach ($hints[$platformType] as $fragment) {
            if (str_contains($needle, $fragment)) {
                return;
            }
        }

        try {
            Injector::inst()->get(LoggerInterface::class)->warning(sprintf(
                'AIClient: AI_MODEL "%s" does not look like a "%s" model — check AI_PLATFORM_TYPE / AI_MODEL.',
                $model,
                $platformType
            ));
        } catch (\Throwable) {
            // logging is best-effort; never break a request over a warning
        }
    }

    protected function resolveInstructions(string $instructions): string
    {
        return $this->limit(
            $instructions ?: $this->config()->get('default_instructions'),
            'max_instructions_length'
        );
    }

    protected function limit(?string $value, string $configKey): string
    {
        return mb_substr((string) $value, 0, (int) $this->config()->get($configKey));
    }
}
