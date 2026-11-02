<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * A site-wide cap on emails per rolling day (PRISM_EMAIL_DAILY_CAP), counting
 * judge invites and account lock emails together. Amazon SES charges per
 * email, and a flood of mail can hurt the sending reputation of the account.
 */
final class EmailBudget
{
    private const KEY = 'emails-sent-site';

    private const WINDOW_SECONDS = 86400;

    public static function available(): bool
    {
        return ! RateLimiter::tooManyAttempts(self::KEY, max(1, (int) config('prism.email_daily_cap')));
    }

    public static function record(): void
    {
        RateLimiter::hit(self::KEY, self::WINDOW_SECONDS);
    }

    public static function retryAfter(): int
    {
        return max(1, RateLimiter::availableIn(self::KEY));
    }
}
