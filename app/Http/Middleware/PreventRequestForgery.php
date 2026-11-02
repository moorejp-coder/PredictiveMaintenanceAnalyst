<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

/**
 * Laravel's CSRF check, without the XSRF-TOKEN cookie.
 *
 * Laravel sets XSRF-TOKEN for JavaScript libraries that read it. The cookie
 * cannot be httpOnly, because JavaScript must read it. PRISM never reads it:
 * forms send the token in a hidden _token field (@csrf) and the dashboard
 * scripts send it in an X-CSRF-TOKEN header from the csrf-token meta tag. So
 * PRISM sets no cookie JavaScript can read. The token check stays on.
 */
class PreventRequestForgery extends Middleware
{
    /** @var bool */
    protected $addHttpCookie = false;
}
