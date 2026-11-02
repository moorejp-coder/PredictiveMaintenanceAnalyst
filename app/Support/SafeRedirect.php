<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\VerifyRequestOrigin;
use Illuminate\Http\Request;

/**
 * The redirect allowlist. A redirect goes only to a path on PRISM itself:
 * the request's own origin or APP_URL's origin. Anything else (another
 * host, "//host", "/\host", "javascript:", control characters) is replaced
 * with the fallback path.
 */
final class SafeRedirect
{
    /**
     * A same-site path such as "/account?x=1", or $fallback.
     */
    public static function path(mixed $url, string $fallback = '/', ?Request $request = null): string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2048) {
            return $fallback;
        }

        // Browsers read "\" as "/" and skip tabs and newlines in URLs, so
        // "/\evil.example" and "/\t/evil.example" would leave the site.
        if (preg_match('/[\\\\\x00-\x1F\x7F]/', $url) === 1) {
            return $fallback;
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? $fallback : $url;
        }

        $origin = VerifyRequestOrigin::originOf($url);
        if ($origin === null || ! in_array($origin, self::allowedOrigins($request ?? request()), true)) {
            return $fallback;
        }

        $parts = parse_url($url);
        $path = '/'.ltrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#'.$parts['fragment'] : '';

        return $path.$query.$fragment;
    }

    /** @return list<string> the request's own origin and APP_URL's origin */
    public static function allowedOrigins(Request $request): array
    {
        return array_values(array_unique(array_filter([
            VerifyRequestOrigin::originOf($request->getSchemeAndHttpHost()),
            VerifyRequestOrigin::originOf((string) config('app.url')),
        ])));
    }
}
