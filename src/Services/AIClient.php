<?php

namespace XD\SilverstripeAI\Services;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

class AIClient
{
    use Injectable;
    use Configurable;

    private static int $max_text_length = 5000;
    private static int $max_instructions_length = 1000;

    private static string $default_instructions = 'You are a helpful assistant and SEO expert.';

    /**
     * Generate a text response from a prompt.
     */
    public function generateText(string $text, string $instructions = ''): string
    {
        $messages = new MessageBag(
            Message::forSystem($this->resolveInstructions($instructions)),
            Message::ofUser($this->limit($text, 'max_text_length'))
        );

        return $this->invoke($messages);
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

        $output = $this->invoke($messages);

        // Strip markdown code fences if present
        $output = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($output));

        $decoded = json_decode($output, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('AI returned invalid JSON: ' . $output);
        }

        return $decoded;
    }

    protected function invoke(MessageBag $messages): string
    {
        $platform = $this->getPlatform();
        $model    = Environment::getEnv('AI_MODEL') ?: 'gpt-4o-mini';

        return $platform->invoke($model, $messages)->asText();
    }

    protected function getPlatform()
    {
        $platformType = strtolower(Environment::getEnv('AI_PLATFORM_TYPE') ?: 'openai');
        $apiKey       = Environment::getEnv('AI_API_KEY');

        if (!$apiKey) {
            throw new \RuntimeException('AI API key not configured');
        }

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
