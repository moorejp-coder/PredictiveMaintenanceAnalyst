<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One small AI call with a built-in fallback.
 *
 * A step receives a small structured input built in PHP. It never receives the
 * scoring rules. The reply must parse and pass checks, or the fallback text
 * shows. Good replies are cached for an hour. Fallbacks are cached for a
 * minute, so a slow API is not retried on every click.
 *
 * run() returns: source ("ai", "cache" or "fallback"), data, tokens
 * (input and output counts, or null when no call was made) and reason. The
 * reason is null unless the source is "fallback". Then it is one of low_risk,
 * no_key, timeout, api_error or bad_reply.
 */
abstract class AiStep
{
    private const AI_TTL_SECONDS = 3600;

    private const FALLBACK_TTL_SECONDS = 60;

    public function __construct(protected readonly AiClient $ai)
    {
    }

    abstract protected function name(): string;

    abstract protected function system(): string;

    abstract protected function maxTokens(): int;

    /** Validated reply data, or null when the reply is unusable. */
    abstract protected function parse(string $text, array $input): ?array;

    /** Deterministic text built from the same input. Must always work. */
    abstract protected function fallback(array $input): array;

    /** Return false when there is nothing worth an AI call. */
    protected function shouldCall(array $input): bool
    {
        return true;
    }

    /**
     * The built-in text with no AI call, for a caller that already knows why.
     *
     * @return array{source: string, data: array, tokens: null, reason: string}
     */
    public function offline(array $input, string $reason): array
    {
        return $this->finish('fallback', $this->fallback($input), null, $input, $reason);
    }

    /** @return array{source: string, data: array, tokens: array{input: int, output: int}|null, reason: string|null} */
    public function run(array $input): array
    {
        if (! $this->shouldCall($input)) {
            return $this->finish('fallback', $this->fallback($input), null, $input, 'low_risk');
        }

        if (! $this->ai->enabled()) {
            return $this->finish('fallback', $this->fallback($input), null, $input, 'no_key');
        }

        $key = 'ai:'.$this->name().':'.sha1((string) json_encode($input));

        $cached = $this->cacheGet($key);
        if (is_array($cached) && isset($cached['source'], $cached['data'])) {
            $source = $cached['source'] === 'ai' ? 'cache' : 'fallback';

            $reason = $source === 'fallback' ? ($cached['reason'] ?? 'api_error') : null;

            return $this->finish($source, $cached['data'], $cached['tokens'] ?? null, $input, $reason);
        }

        $reply = $this->ai->complete(
            $this->system(),
            "Risk result:\n".json_encode($input, JSON_UNESCAPED_SLASHES),
            $this->maxTokens(),
        );
        $data = $reply === null ? null : $this->parse($reply['text'], $input);

        if ($data === null) {
            $result = [
                'source' => 'fallback',
                'data' => $this->fallback($input),
                'tokens' => null,
                'reason' => $reply === null ? ($this->ai->failure() ?? 'api_error') : 'bad_reply',
            ];
            $this->cachePut($key, $result, self::FALLBACK_TTL_SECONDS);
        } else {
            $result = [
                'source' => 'ai',
                'data' => $data,
                'tokens' => ['input' => $reply['input_tokens'], 'output' => $reply['output_tokens']],
                'reason' => null,
            ];
            $this->cachePut($key, $result, self::AI_TTL_SECONDS);
        }

        return $this->finish($result['source'], $result['data'], $result['tokens'], $input, $result['reason']);
    }

    private function finish(string $source, array $data, ?array $tokens, array $input, ?string $reason = null): array
    {
        Log::info('ai_step', [
            'step' => $this->name(),
            'source' => $source,
            'reason' => $reason,
            'input_tokens' => $tokens['input'] ?? null,
            'output_tokens' => $tokens['output'] ?? null,
        ]);

        return ['source' => $source, 'data' => $data, 'tokens' => $tokens, 'reason' => $reason];
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    private function cachePut(string $key, array $value, int $seconds): void
    {
        try {
            Cache::put($key, $value, $seconds);
        } catch (\Throwable) {
            // A broken cache must not break the page.
        }
    }

    /** Decode a JSON object from a reply, even if the model wraps it in a code fence. */
    public static function decodeJson(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** A trimmed non-empty string no longer than $max characters, or null. */
    public static function cleanString(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value !== '' && mb_strlen($value) <= $max ? $value : null;
    }

    /** A list of 1 to $limit clean strings, or null if any item is unusable. */
    public static function cleanList(mixed $value, int $min, int $limit, int $maxEach): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $items = [];
        foreach (array_slice($value, 0, $limit) as $item) {
            $clean = self::cleanString($item, $maxEach);
            if ($clean === null) {
                return null;
            }
            $items[] = $clean;
        }

        return count($items) >= $min ? $items : null;
    }
}
