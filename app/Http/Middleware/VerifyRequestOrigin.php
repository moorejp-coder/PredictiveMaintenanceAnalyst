<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SafeRedirect;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * A second CSRF check on every request that changes data (POST, PUT, PATCH,
 * DELETE). The CSRF token check stays on. This one refuses a request whose
 * browser says it came from another site:
 *
 * 1. Sec-Fetch-Site is "cross-site" or "same-site". Browsers set this header
 *    and page scripts cannot change it.
 * 2. Origin is present and is not PRISM's own origin. "null" counts as
 *    another site (sandboxed frames, cross-site redirects).
 * 3. No Origin, and Referer is a full URL on another origin.
 *
 * A request with none of these headers passes on to the CSRF token check,
 * so old browsers and privacy tools that strip headers keep working.
 */
class VerifyRequestOrigin
{
    public const MESSAGE = 'This request came from another site and was refused.';

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $reason = $this->refusalReason($request);
        if ($reason !== null) {
            Log::warning('Refused a cross-site request', [
                'reason' => $reason,
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'ip' => $request->ip(),
                'origin' => mb_substr((string) $request->headers->get('Origin', ''), 0, 200),
                'referer_origin' => self::originOf((string) $request->headers->get('Referer', '')),
                'sec_fetch_site' => mb_substr((string) $request->headers->get('Sec-Fetch-Site', ''), 0, 20),
            ]);

            abort(403, self::MESSAGE);
        }

        return $next($request);
    }

    private function refusalReason(Request $request): ?string
    {
        $site = strtolower((string) $request->headers->get('Sec-Fetch-Site', ''));
        if ($site === 'cross-site' || $site === 'same-site') {
            return 'sec-fetch-site';
        }

        $allowed = SafeRedirect::allowedOrigins($request);

        if ($request->headers->has('Origin')) {
            $origin = self::originOf((string) $request->headers->get('Origin'));

            return $origin !== null && in_array($origin, $allowed, true) ? null : 'origin';
        }

        // Browsers send a full URL. A Referer with no scheme and host says
        // nothing about the site, so it is treated like no Referer.
        $origin = self::originOf((string) $request->headers->get('Referer', ''));
        if ($origin !== null && ! in_array($origin, $allowed, true)) {
            return 'referer';
        }

        return null;
    }

    /** scheme://host[:port] in lower case, with the default port left out, or null when the value is not an http(s) URL. */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port !== null && $port !== $default ? ':'.$port : '');
    }
}
