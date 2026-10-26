<?php

use App\Http\Middleware\RedirectTrailingSlash;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // nginx on the Elastic Beanstalk instance ends HTTPS and passes the
        // request on over HTTP. Trust its forwarded headers so Laravel builds
        // https links.
        $middleware->trustProxies(at: '*');
        $middleware->prepend(RedirectTrailingSlash::class);
        // Prepended last, so it runs first and every response gets the headers.
        $middleware->prepend(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A JSON API route rejects input outside its bounds with HTTP 400 and
        // the same message and errors body Laravel sends by default. Form
        // pages keep their redirect back with the errors.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 400);
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
