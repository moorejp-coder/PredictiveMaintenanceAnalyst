<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the override log page. The log holds names and free text typed by
 * visitors, so it is not public. Set OVERRIDE_LOG_PASSWORD in the environment.
 * With no password set the page does not exist (404). Sign in with any user
 * name and that password.
 */
class RequireOverrideLogPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('prism.override_log_password');

        if ($password === '') {
            abort(404);
        }

        if (! hash_equals($password, (string) $request->getPassword())) {
            return response('Password required.', 401, [
                'WWW-Authenticate' => 'Basic realm="PRISM override log", charset="UTF-8"',
            ]);
        }

        return $next($request);
    }
}
