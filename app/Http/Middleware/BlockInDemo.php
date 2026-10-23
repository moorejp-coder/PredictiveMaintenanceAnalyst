<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the judge demo read-only. A demo session gets 403 on the routes that
 * write, so nothing a judge types reaches the database.
 */
class BlockInDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DemoMode::active($request)) {
            return response()->json([
                'message' => 'This is the read-only demo. Nothing is saved.',
            ], 403);
        }

        return $next($request);
    }
}
