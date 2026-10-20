<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Sign in and sign out. PRISM has no sign-up page. The only account is the
 * judge account that JudgeSeeder creates from environment properties.
 * Everything here is inactive until REQUIRE_LOGIN=true.
 */
class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        // Switched off: there is nothing to sign in to.
        if (! config('prism.require_login')) {
            return redirect('/');
        }

        return view('login');
    }

    public function login(Request $request): RedirectResponse
    {
        abort_unless(config('prism.require_login'), 404);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        // One message for an unknown email and a wrong password, so the
        // form never says which accounts exist.
        if (! Auth::attempt($credentials)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'The email or password is wrong.']);
        }

        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        abort_unless(config('prism.require_login'), 404);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
