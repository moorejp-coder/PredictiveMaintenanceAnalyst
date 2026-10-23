<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sign-in gate, switched by REQUIRE_LOGIN. Off (the default), every
 * request passes and the dashboard is public. On, a guest is sent to /login
 * (or gets 401 JSON on /api/ routes). The setting is read on each request,
 * so tests can flip it.
 */
class RequireLogin
{
    public function __construct(private Authenticate $authenticate) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('prism.require_login')) {
            return $next($request);
        }

        return $this->authenticate->handle($request, $next);
    }
}
