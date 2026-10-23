<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send /faq/ to /faq with a permanent redirect. The Elastic Beanstalk
 * nginx server ignores .htaccess, so Laravel has to do this itself.
 */
class RedirectTrailingSlash
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();

        if ($path !== '/' && str_ends_with($path, '/') && in_array($request->method(), ['GET', 'HEAD'], true)) {
            $query = $request->getQueryString();

            return redirect((rtrim($path, '/') ?: '/').($query ? '?'.$query : ''), 301);
        }

        return $next($request);
    }
}
