<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\SafeRedirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's back() and the redirect after a failed form check go to the
 * Referer header as sent. This removes a Referer that is not a page on
 * PRISM, so those redirects fall back to the last PRISM page in the session
 * and never leave the site.
 */
class DropForeignReferer
{
    public function handle(Request $request, Closure $next): Response
    {
        $referer = $request->headers->get('Referer');
        if (is_string($referer) && $referer !== '' && SafeRedirect::path($referer, '', $request) === '') {
            $request->headers->remove('Referer');
        }

        return $next($request);
    }
}
