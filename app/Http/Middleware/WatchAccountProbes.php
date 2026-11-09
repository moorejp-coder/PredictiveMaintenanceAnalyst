<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Alerts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts, per IP address, how many different accounts its requests name.
 * Watches the POST routes that take an email: sign-in, the password page and
 * the unlock page. It is global middleware, so it runs before the rate limit
 * and a request the limit then refuses still counts. A script that tries a
 * list of emails is exactly the traffic the limit refuses. It only reads. A
 * problem here never changes the answer.
 */
class WatchAccountProbes
{
    /** Paths of the POST routes that name an account by email (routes/web.php). */
    private const PATHS = ['login', 'set-password', 'set-password/check', 'unlock-account', 'unlock-account/check', 'unlock-account/password'];

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $email = $request->isMethod('POST') && $request->is(...self::PATHS) ? $request->input('email') : null;
            if (is_string($email) && trim($email) !== '') {
                Alerts::accountProbed(Str::lower(trim(mb_substr($email, 0, 255))), $request);
            }
        } catch (Throwable) {
            // A signal, not a gate.
        }

        return $next($request);
    }
}
