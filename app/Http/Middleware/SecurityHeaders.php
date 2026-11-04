<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds browser security headers to every page Laravel serves.
 *
 * Scripts live in files under public/js and script-src allows only PRISM's own
 * origin, so no inline script runs. The Content-Security-Policy uses a new
 * random nonce on each request for the inline style blocks, which the views
 * tag through the shared $cspNonce value.
 * Inline style attributes stay allowed (style-src-attr), because several
 * pages use them. Files nginx serves directly (CSS, fonts, images) do not
 * pass through Laravel. nginx adds the same headers to those files, with a
 * stricter policy for content (.platform/nginx/conf.d/elasticbeanstalk/
 * laravel.conf). Keep both lists in step; SecurityHeadersTest checks them.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        View::share('cspNonce', $nonce);

        $response = $next($request);

        // HSTS and upgrade-insecure-requests only make sense on HTTPS. The
        // APP_URL check covers a proxy that does not forward the scheme.
        $https = $request->isSecure()
            || str_starts_with((string) config('app.url'), 'https://');

        $csp = [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'nonce-{$nonce}'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];
        if ($https) {
            $csp[] = 'upgrade-insecure-requests';
        }

        $headers = [
            'Content-Security-Policy' => implode('; ', $csp),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        // A controller that set its own value (the set-password page sends
        // Referrer-Policy: same-origin) keeps it.
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
