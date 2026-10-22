<?php

namespace App\Support;

class CanonicalUrl
{
    /**
     * The one origin search engines should treat as canonical.
     *
     * Uses APP_URL when it names a real host, so the same page reached
     * through another host (for example the raw Elastic Beanstalk URL and the
     * public domain name) always points to one address. Falls back to the
     * request origin for local development.
     */
    public static function base(): string
    {
        $configured = rtrim((string) config('app.url'), '/');
        $host = parse_url($configured, PHP_URL_HOST);

        if ($host && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return $configured;
        }

        return request()->getSchemeAndHttpHost();
    }

    /** Absolute canonical URL for a path. The root has no trailing slash. */
    public static function for(string $path): string
    {
        $path = trim($path, '/');

        return self::base().($path === '' ? '' : '/'.$path);
    }

    /** Canonical URL for the current request. Drops the query string and any trailing slash. */
    public static function current(): string
    {
        return self::for(request()->path());
    }
}
