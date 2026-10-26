<?php

declare(strict_types=1);

namespace App\Http\Middleware\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts wrong passwords on a page guarded by one shared password (HTTP Basic).
 * Ten wrong guesses from one IP address lock that address out of the page for
 * 15 minutes. A request with no password at all (the browser's first visit,
 * before the prompt) is not a guess and is not counted.
 */
trait LimitsPasswordGuesses
{
    private const MAX_GUESSES = 10;

    private const LOCKOUT_SECONDS = 900;

    private function lockedOut(Request $request, string $scope): ?Response
    {
        $key = $this->guessKey($request, $scope);

        if (! RateLimiter::tooManyAttempts($key, self::MAX_GUESSES)) {
            return null;
        }

        return response('Too many wrong passwords. Wait 15 minutes, then try again.', 429, [
            'Retry-After' => (string) RateLimiter::availableIn($key),
        ]);
    }

    private function recordGuess(Request $request, string $scope): void
    {
        if ((string) $request->getPassword() === '') {
            return;
        }

        RateLimiter::hit($this->guessKey($request, $scope), self::LOCKOUT_SECONDS);

        Log::warning('Wrong password on a protected page', [
            'scope' => $scope,
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);
    }

    private function clearGuesses(Request $request, string $scope): void
    {
        RateLimiter::clear($this->guessKey($request, $scope));
    }

    private function guessKey(Request $request, string $scope): string
    {
        return 'password-page:'.$scope.'|'.$request->ip();
    }
}
