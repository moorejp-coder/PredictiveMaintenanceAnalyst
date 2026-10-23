<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The read-only judge demo. GET /demo sets a flag in the visitor's session.
 * The flag opens the dashboard without an account and blocks the two routes
 * that write (the override log and AI ratings). It grants nothing else.
 * A signed-in user is never treated as a demo visitor.
 */
final class DemoMode
{
    public const SESSION_KEY = 'prism_demo';

    public static function active(Request $request): bool
    {
        return (bool) config('prism.require_login')
            && $request->hasSession()
            && $request->session()->get(self::SESSION_KEY) === true
            && ! $request->user();
    }
}
