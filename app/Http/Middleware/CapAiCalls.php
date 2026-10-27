<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * A site-wide daily ceiling on AI routes. The per-visitor throttle stops one
 * address. This stops many addresses, including anonymous Judge Demo sessions,
 * from running up the AI bill. Set PRISM_AI_DAILY_CAP to change the number.
 */
class CapAiCalls
{
    private const KEY = 'ai-daily-cap';

    private const WINDOW_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $cap = max(1, (int) config('prism.ai_daily_cap'));

        if (RateLimiter::tooManyAttempts(self::KEY, $cap)) {
            return response()->json([
                'message' => 'The AI step has reached its daily limit. Try again tomorrow.',
            ], 429, ['Retry-After' => (string) RateLimiter::availableIn(self::KEY)]);
        }

        RateLimiter::hit(self::KEY, self::WINDOW_SECONDS);

        return $next($request);
    }
}
