<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * An absolute limit on a signed-in session. The idle limit (SESSION_LIFETIME,
 * 30 minutes) never ends a tab that keeps polling, so this ends the session
 * a set number of days after sign-in, however busy it is. The user signs in
 * again. A session with no sign-in time (opened before this check existed)
 * gets one now.
 */
class EnforceSessionAge
{
    public const SESSION_KEY = 'signed_in_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->user()) {
            return $next($request);
        }

        $session = $request->session();
        $signedInAt = $session->get(self::SESSION_KEY);

        if (! is_int($signedInAt)) {
            $session->put(self::SESSION_KEY, now()->getTimestamp());

            return $next($request);
        }

        $maxSeconds = max(1, (int) config('prism.session_max_days')) * 86400;

        if (now()->getTimestamp() - $signedInAt <= $maxSeconds) {
            return $next($request);
        }

        Auth::logout();
        $session->invalidate();
        $session->regenerateToken();

        return $request->expectsJson()
            ? response()->json(['message' => 'Unauthenticated.'], 401)
            : redirect('/login');
    }
}
