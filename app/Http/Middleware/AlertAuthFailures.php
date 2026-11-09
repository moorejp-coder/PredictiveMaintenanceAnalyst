<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Alerts;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Feeds the "401 and 403 above the usual level" alert (Alerts::authFailure).
 * Global middleware, like AuditApiErrors, so it sees every route and an
 * unknown path. It counts an answer of 401 or 403, and a refusal that a
 * policy shows as 404 so a stranger cannot tell an entry exists.
 *
 * It runs in terminate(), after the response has gone out.
 */
class AlertAuthFailures
{
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $refused = in_array($response->getStatusCode(), [401, 403], true)
            || $request->attributes->get(AuditLog::DENIAL_LOGGED) === true;
        if (! $refused) {
            return;
        }

        try {
            Alerts::authFailure($request);
        } catch (Throwable) {
            // The counters are a signal, not a gate. The response is already sent.
        }
    }
}
