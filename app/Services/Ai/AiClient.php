<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Anthropic Messages API.
 *
 * It never throws. A missing key, a timeout, a bad status or an empty reply
 * all return null, so the caller shows its built-in text instead.
 */
final class AiClient
{
    public function __construct(
        private readonly ?string $key,
        private readonly string $model,
        private readonly int $timeoutSeconds = 8,
        private readonly string $baseUrl = 'https://api.anthropic.com',
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            config('services.anthropic.key'),
            (string) config('services.anthropic.model'),
            (int) config('services.anthropic.timeout', 8),
        );
    }

    public function enabled(): bool
    {
        return is_string($this->key) && $this->key !== '';
    }

    /**
     * @return array{text: string, input_tokens: int, output_tokens: int}|null
     */
    public function complete(string $system, string $user, int $maxTokens): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->key,
                'anthropic-version' => '2023-06-01',
            ])
                ->connectTimeout(3)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post($this->baseUrl.'/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $user]],
                ]);
        } catch (\Throwable $e) {
            Log::warning('ai_call_failed', ['reason' => 'exception', 'class' => $e::class]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('ai_call_failed', ['reason' => 'status', 'status' => $response->status()]);

            return null;
        }

        $text = data_get($response->json(), 'content.0.text');
        if (! is_string($text) || trim($text) === '') {
            Log::warning('ai_call_failed', ['reason' => 'empty_reply']);

            return null;
        }

        return [
            'text' => $text,
            'input_tokens' => (int) data_get($response->json(), 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($response->json(), 'usage.output_tokens', 0),
        ];
    }
}
