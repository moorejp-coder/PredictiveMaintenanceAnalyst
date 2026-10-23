<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\DemoMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Judge Demo entry. No account, no password, no email. The link works any
 * number of times. It only sets a session flag (see DemoMode), so it is safe
 * to open from a GET link in the README.
 */
class DemoController extends Controller
{
    public function start(Request $request): RedirectResponse
    {
        // With the sign-in off the dashboard is already public.
        if (! config('prism.require_login')) {
            return redirect('/');
        }

        $request->session()->put(DemoMode::SESSION_KEY, true);

        return redirect('/');
    }
}
