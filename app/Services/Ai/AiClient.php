<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Anthropic Messages API.
 *
 * It never throws. A missing key, a timeout, a bad status or an empty reply
 * all return null, so the caller shows its built-in text instead. After a
 * failed call, failure() names the reason: no_key, timeout, api_error or bad_reply.
 */
final class AiClient
{
    private const THINKING_HEADROOM = 1024;

    public function __construct(
        private readonly ?string $key,
        private readonly string $model,
        private readonly int $timeoutSeconds = 8,
        private readonly string $baseUrl = 'https://api.anthropic.com',
    ) {}

    private ?string $failure = null;

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

    /** Why the last call returned null, or null when it did not fail. */
    public function failure(): ?string
    {
        return $this->failure;
    }

    /**
     * @return array{text: string, input_tokens: int, output_tokens: int}|null
     */
    public function complete(string $system, string $user, int $maxTokens): ?array
    {
        $json = $this->send([
            'model' => $this->model,
            // Thinking tokens count toward max_tokens, so leave room for them
            // or the model can run out before it writes the answer.
            'max_tokens' => $maxTokens + self::THINKING_HEADROOM,
            'output_config' => ['effort' => 'low'],
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
        ], $this->timeoutSeconds);
        if ($json === null) {
            return null;
        }

        $text = self::firstText($json);
        if ($text === null) {
            $this->failure = 'bad_reply';
            Log::warning('ai_call_failed', [
                'reason' => 'empty_reply',
                'stop_reason' => data_get($json, 'stop_reason'),
                'block_types' => self::blockTypes($json),
            ]);

            return null;
        }

        return [
            'text' => $text,
            'input_tokens' => (int) data_get($json, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($json, 'usage.output_tokens', 0),
        ];
    }

    /**
     * One turn of a tool conversation. Returns the reply blocks exactly as the
     * API sent them, so the caller passes them back unchanged on the next turn.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @param  string  $toolChoice  "auto" lets Claude call tools, "none" forces a final answer
     * @return array{content: list<array<string, mixed>>, stop_reason: string|null, input_tokens: int, output_tokens: int}|null
     */
    public function converse(string $system, array $messages, array $tools, int $maxTokens, string $toolChoice, int $timeoutSeconds): ?array
    {
        $json = $this->send([
            'model' => $this->model,
            'max_tokens' => $maxTokens + self::THINKING_HEADROOM,
            'output_config' => ['effort' => 'low'],
            'system' => $system,
            'tools' => $tools,
            'tool_choice' => ['type' => $toolChoice],
            'messages' => $messages,
        ], max(1, min($timeoutSeconds, $this->timeoutSeconds)));
        if ($json === null) {
            return null;
        }

        $content = data_get($json, 'content');
        if (! is_array($content) || $content === []) {
            $this->failure = 'bad_reply';
            Log::warning('ai_call_failed', ['reason' => 'empty_reply', 'stop_reason' => data_get($json, 'stop_reason')]);

            return null;
        }

        return [
            'content' => array_values($content),
            'stop_reason' => data_get($json, 'stop_reason'),
            'input_tokens' => (int) data_get($json, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($json, 'usage.output_tokens', 0),
        ];
    }

    /**
     * The first non-empty text block of a reply, or null.
     *
     * @param  array<string, mixed>  $json
     */
    public static function firstText(array $json): ?string
    {
        // The reply may start with a non-text block (for example thinking), so use the first text block.
        foreach ((array) data_get($json, 'content', []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null) && trim($block['text']) !== '') {
                return $block['text'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<string>
     */
    private static function blockTypes(array $json): array
    {
        return array_values(array_map(fn ($b) => is_array($b) && is_string($b['type'] ?? null) ? $b['type'] : '?', (array) data_get($json, 'content', [])));
    }

    /**
     * POST one Messages API request. Never throws. Returns the decoded reply,
     * or null after setting failure().
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function send(array $body, int $timeoutSeconds): ?array
    {
        $this->failure = null;

        if (! $this->enabled()) {
            $this->failure = 'no_key';

            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->key,
                'anthropic-version' => '2023-06-01',
            ])
                ->connectTimeout(3)
                ->timeout($timeoutSeconds)
                ->acceptJson()
                ->post($this->baseUrl.'/v1/messages', $body);
        } catch (\Throwable $e) {
            $this->failure = $e instanceof ConnectionException ? 'timeout' : 'api_error';
            Log::warning('ai_call_failed', ['reason' => 'exception', 'class' => $e::class]);

            return null;
        }

        if (! $response->successful()) {
            $this->failure = 'api_error';
            Log::warning('ai_call_failed', ['reason' => 'status', 'status' => $response->status()]);

            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }
}
