<?php

use App\Http\Middleware\AdminOrDemo;
use App\Http\Middleware\AuthorizeResource;
use App\Http\Middleware\BindSessionToClient;
use App\Http\Middleware\EnforceSessionAge;
use App\Http\Middleware\RedirectTrailingSlash;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ErrorReference;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        // authorize.resource:<check>,<routeParam> (see AuthorizeResource).
        $middleware->alias([
            'authorize.resource' => AuthorizeResource::class,
            'admin.or.demo' => AdminOrDemo::class,
        ]);
        $middleware->prepend(RedirectTrailingSlash::class);
        // Prepended last, so it runs first and every response gets the headers.
        $middleware->prepend(SecurityHeaders::class);
        // A changed password ends every other session of that account.
        $middleware->web(append: [AuthenticateSession::class, EnforceSessionAge::class, BindSessionToClient::class]);
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
        // Production never shows a stack trace, file path or query on a page,
        // even if APP_DEBUG is switched on by mistake.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (app()->isProduction() && config('app.debug')) {
                config(['app.debug' => false]);
            }

            return null;
        });

        // One reference per failed request. It goes into the log line that holds
        // the full error and into the response, so a reported reference finds
        // the matching entry in the server log.
        $exceptions->context(function (\Throwable $e) {
            return ['error_reference' => ErrorReference::for(request())];
        });

        // Catch-all for API routes and every request that wants JSON. The
        // client gets a short generic message and the status code, never the
        // exception text, class, file, line, trace, route or query. Laravel
        // has already written the full error and trace to the server log
        // before this runs (HTTP errors such as a plain 404 are not logged).
        // Validation and sign-in answers keep Laravel's own safe handling.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }
            if ($e instanceof ValidationException || $e instanceof AuthenticationException) {
                return null;
            }

            [$status, $headers] = match (true) {
                $e instanceof ModelNotFoundException => [404, []],
                $e instanceof AuthorizationException => [$e->status() ?? 403, []],
                $e instanceof TokenMismatchException => [419, []],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getHeaders()],
                default => [500, []],
            };

            // Messages the app wrote itself for these codes (for example the
            // demo's 403) pass through. Everything else is generic, because
            // Laravel puts route and model names into 404 and 405 messages.
            $keep = [400, 401, 403];
            $message = ($e instanceof HttpExceptionInterface || $e instanceof AuthorizationException)
                && in_array($status, $keep, true) && trim($e->getMessage()) !== ''
                ? $e->getMessage()
                : match (true) {
                    $status === 404 => 'Not found.',
                    $status === 405 => 'This request is not allowed.',
                    $status === 419 => 'This page expired. Reload it and try again.',
                    $status === 429 => 'Too many requests. Wait a minute and try again.',
                    $status === 403 => 'You do not have access to this.',
                    $status === 401 => 'Please sign in to continue.',
                    $status >= 500 => 'Something went wrong on our side. Try again in a minute.',
                    default => 'The request could not be completed.',
                };

            $body = ['message' => $message];
            if ($status >= 500) {
                $body['reference'] = ErrorReference::for($request);
            }

            return response()->json($body, $status, array_merge($headers, ['Cache-Control' => 'no-store']));
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
