<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the page where the entrant invites judges. Set INVITE_ADMIN_PASSWORD
 * in the environment (a different value from every other password). With no
 * password set the page does not exist (404). Sign in with any user name and
 * that password.
 */
class RequireInviteAdminPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('prism.invite_admin_password');

        if ($password === '') {
            abort(404);
        }

        if (! hash_equals($password, (string) $request->getPassword())) {
            return response('Password required.', 401, [
                'WWW-Authenticate' => 'Basic realm="PRISM judge invites", charset="UTF-8"',
            ]);
        }

        return $next($request);
    }
}
