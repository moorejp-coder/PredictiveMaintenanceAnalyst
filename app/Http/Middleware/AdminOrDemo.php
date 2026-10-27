<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The override review page. A read-only Judge Demo session passes, because the
 * controller then shows fixed sample rows and reads nothing from the log.
 * Everyone else must be a signed-in admin: a guest gets 401 (or a redirect to
 * /login), any other account gets 403, and the refusal is logged.
 */
class AdminOrDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DemoMode::active($request)) {
            return $next($request);
        }

        return (new AuthorizeResource)->handle($request, $next, 'is-admin');
    }
}
