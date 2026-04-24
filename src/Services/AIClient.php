<?php

namespace XD\SilverstripeAI\Services;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use XD\SilverstripeAI\Models\AIRequestLog;

class AIClient
{
    use Injectable;
    use Configurable;

    private static int $max_text_length = 5000;
    private static int $max_instructions_length = 1000;

    private static string $default_instructions = 'You are a helpful assistant and SEO expert.';

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

        $response = $platform->invoke($model, $messages);

        // Extract token usage via Symfony AI metadata
        $promptTokens     = null;
        $completionTokens = null;

        try {
            $usage = $response->getMetadata()->get('token_usage');
            if ($usage !== null) {
                $promptTokens = method_exists($usage, 'getPromptTokens')
                    ? $usage->getPromptTokens()
                    : ($usage->promptTokens ?? $usage->inputTokens ?? null);

                $completionTokens = method_exists($usage, 'getCompletionTokens')
                    ? $usage->getCompletionTokens()
                    : ($usage->completionTokens ?? $usage->outputTokens ?? null);
            }
        } catch (\Throwable) {
            // Usage not available for this platform/version
        }

        $estimatedCost = $this->estimateCost($model, $promptTokens, $completionTokens);

        AIRequestLog::log($platformType, $model, $mode, $promptTokens, $completionTokens, $estimatedCost);

        return $response->asText();
    }

    protected function estimateCost(string $model, ?int $promptTokens, ?int $completionTokens): ?float
    {
        if ($promptTokens === null && $completionTokens === null) {
            return null;
        }

        $pricing = $this->config()->get('model_pricing');
        $rates   = $pricing[$model] ?? null;

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

        return match ($platformType) {
            'openai'              => \Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory::create($apiKey),
            'claude', 'anthropic' => \Symfony\AI\Platform\Bridge\Anthropic\PlatformFactory::create($apiKey),
            'azure'               => \Symfony\AI\Platform\Bridge\Azure\PlatformFactory::create($apiKey),
            'vertex'              => \Symfony\AI\Platform\Bridge\VertexAI\PlatformFactory::create($apiKey),
            'openrouter'          => \Symfony\AI\Platform\Bridge\OpenRouter\PlatformFactory::create($apiKey),
            default               => throw new \InvalidArgumentException("Unknown AI platform type: $platformType"),
        };
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
        return substr((string) $value, 0, $this->config()->get($configKey));
    }
}
