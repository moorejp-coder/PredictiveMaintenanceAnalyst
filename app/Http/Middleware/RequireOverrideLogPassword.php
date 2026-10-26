<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\LimitsPasswordGuesses;
use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the override log page. The log holds names and free text typed by
 * visitors, so it is not public. Set OVERRIDE_LOG_PASSWORD in the environment.
 * With no password set the page does not exist (404). Sign in with any user
 * name and that password. A read-only demo session skips the password because
 * the controller then shows fixed sample rows and reads nothing from the log.
 */
class RequireOverrideLogPassword
{
    use LimitsPasswordGuesses;

    public function handle(Request $request, Closure $next): Response
    {
        if (DemoMode::active($request)) {
            return $next($request);
        }

        $password = (string) config('prism.override_log_password');

        if ($password === '') {
            abort(404);
        }

        if ($locked = $this->lockedOut($request, 'overrides')) {
            return $locked;
        }

        if (! hash_equals($password, (string) $request->getPassword())) {
            $this->recordGuess($request, 'overrides');

            return response('Password required.', 401, [
                'WWW-Authenticate' => 'Basic realm="PRISM override log", charset="UTF-8"',
            ]);
        }

        $this->clearGuesses($request, 'overrides');

        return $next($request);
    }
}
