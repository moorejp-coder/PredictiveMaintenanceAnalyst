<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Raises an alert when /api/ routes answer with too many errors.
 *
 * Every 4xx and 5xx answer from a /api/ path counts, including an unknown
 * path (404), a refused request (401, 403), a rate limit (429) and a server
 * error. Two counters run side by side:
 * - per IP address: prism.audit.api_errors_per_ip in the window
 * - all addresses together: prism.audit.api_errors_total in the window
 * When a counter reaches its limit, one api.error_rate_exceeded line goes
 * into the audit log. A counter alerts once per window, so a flood of errors
 * writes a handful of lines and not one per request. The individual refusals
 * (failed sign-ins, denied access) have their own audit lines.
 *
 * It runs in terminate(), after the response has gone out, so it adds no
 * time to a request and a problem here cannot change an answer. Counts live in
 * the cache, which is a file on the live site, so a restart does not reset a
 * window early.
 */
class AuditApiErrors
{
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 400 || ! $request->is('api/*')) {
            return;
        }

        try {
            $window = max(1, (int) config('prism.audit.api_error_window_seconds'));
            $this->count($request, $status, 'ip:'.($request->ip() ?? 'unknown'), (int) config('prism.audit.api_errors_per_ip'), $window);
            $this->count($request, $status, 'all', (int) config('prism.audit.api_errors_total'), $window);
        } catch (Throwable) {
            // The counters are a signal, not a gate. The response is already sent.
        }
    }

    private function count(Request $request, int $status, string $scope, int $limit, int $window): void
    {
        $key = 'audit-api-errors:'.$scope;
        RateLimiter::hit($key, $window);
        $errors = RateLimiter::attempts($key);

        if ($limit < 1 || $errors < $limit || ! Cache::add($key.':alerted', true, $window)) {
            return;
        }

        AuditLog::record('api.error_rate_exceeded', [
            'scope' => str_starts_with($scope, 'ip:') ? 'ip_address' : 'all_addresses',
            'errors' => $errors,
            'limit' => $limit,
            'window_seconds' => $window,
            'last_status' => $status,
        ], AuditLog::ALERT, $request);
    }
}
