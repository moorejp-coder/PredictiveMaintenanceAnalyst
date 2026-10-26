<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ties a signed-in session to the browser that signed in.
 *
 * - A changed browser (User-Agent) ends the session. A stolen session cookie
 *   used from another program or browser stops working.
 * - A changed IP address is only logged. Phones move between networks and
 *   judges share office addresses, so an IP change alone is not proof of theft.
 *
 * Logs hold the user id, both IP addresses and a short hash of the browser
 * string, never the cookie or a password. A session with no stored values
 * (opened before this check existed) is stamped on its next request.
 */
class BindSessionToClient
{
    private const AGENT_KEY = 'client_agent';

    private const IP_KEY = 'client_ip';

    /** Call right after a successful sign-in. */
    public static function stamp(Request $request): void
    {
        $request->session()->put(self::AGENT_KEY, self::agentHash($request));
        $request->session()->put(self::IP_KEY, (string) $request->ip());
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->user()) {
            return $next($request);
        }

        $session = $request->session();

        if (! is_string($session->get(self::AGENT_KEY))) {
            self::stamp($request);

            return $next($request);
        }

        if (! hash_equals($session->get(self::AGENT_KEY), self::agentHash($request))) {
            Log::warning('Session ended: the browser changed', [
                'user_id' => $request->user()->getAuthIdentifier(),
                'ip' => $request->ip(),
                'first_ip' => $session->get(self::IP_KEY),
            ]);

            Auth::logout();
            $session->invalidate();
            $session->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect('/login');
        }

        $firstIp = (string) $session->get(self::IP_KEY);

        if ($firstIp !== '' && $firstIp !== (string) $request->ip()) {
            Log::notice('Session IP address changed', [
                'user_id' => $request->user()->getAuthIdentifier(),
                'first_ip' => $firstIp,
                'ip' => $request->ip(),
            ]);

            // Log once per new address, not on every request.
            $session->put(self::IP_KEY, (string) $request->ip());
        }

        return $next($request);
    }

    private static function agentHash(Request $request): string
    {
        return hash('sha256', (string) $request->userAgent());
    }
}
