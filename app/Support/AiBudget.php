<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * What the AI costs, in US dollars, from the token counts the Anthropic API
 * reports for each call. Two budgets: one for the whole site per rolling day,
 * one per user (or IP address for a visitor with no account) per rolling hour.
 * Prices and budgets live in config/prism.php (ai_budget).
 *
 * Counters hold millionths of a dollar. CapAiCalls answers 429 once a budget
 * is spent. AiClient also checks before every call, so a multi-round
 * investigation stops in the middle and the built-in answer shows.
 */
final class AiBudget
{
    private const SITE_KEY = 'ai-spend-site';

    private const SITE_WINDOW_SECONDS = 86400;

    private const USER_WINDOW_SECONDS = 3600;

    /** Cost of one call in millionths of a dollar, rounded up. */
    public static function costMicros(int $inputTokens, int $outputTokens): int
    {
        // Tokens times dollars per million tokens gives millionths of a dollar.
        $micros = $inputTokens * (float) config('prism.ai_budget.input_usd_per_mtok')
            + $outputTokens * (float) config('prism.ai_budget.output_usd_per_mtok');

        return (int) ceil(max(0.0, $micros));
    }

    public static function record(int $inputTokens, int $outputTokens): void
    {
        $cost = self::costMicros($inputTokens, $outputTokens);
        if ($cost === 0) {
            return;
        }

        RateLimiter::increment(self::SITE_KEY, self::SITE_WINDOW_SECONDS, $cost);
        RateLimiter::increment(self::userKey(), self::USER_WINDOW_SECONDS, $cost);
    }

    public static function siteSpent(): bool
    {
        return RateLimiter::tooManyAttempts(self::SITE_KEY, self::limitMicros('daily_usd'));
    }

    public static function userSpent(): bool
    {
        return RateLimiter::tooManyAttempts(self::userKey(), self::limitMicros('user_hourly_usd'));
    }

    public static function siteRetryAfter(): int
    {
        return max(1, RateLimiter::availableIn(self::SITE_KEY));
    }

    public static function userRetryAfter(): int
    {
        return max(1, RateLimiter::availableIn(self::userKey()));
    }

    /** Dollars spent so far in the current window, for logs and tests. */
    public static function siteSpentUsd(): float
    {
        return RateLimiter::attempts(self::SITE_KEY) / 1_000_000;
    }

    private static function limitMicros(string $name): int
    {
        return max(1, (int) round((float) config("prism.ai_budget.{$name}") * 1_000_000));
    }

    private static function userKey(): string
    {
        $request = request();
        $id = $request->user()?->getAuthIdentifier();

        return 'ai-spend-user:'.(is_scalar($id) ? 'user:'.$id : 'ip:'.$request->ip());
    }
}
