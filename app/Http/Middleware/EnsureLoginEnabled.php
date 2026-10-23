<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The invite and set-password pages belong to the sign-in. While
 * REQUIRE_LOGIN is off they do not exist (404).
 */
class EnsureLoginEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('prism.require_login'), 404);

        return $next($request);
    }
}
