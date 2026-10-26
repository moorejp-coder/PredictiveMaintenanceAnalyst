<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\BindSessionToClient;
use App\Http\Middleware\EnforceSessionAge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Sign in and sign out. PRISM has no sign-up page. Judge accounts come from
 * the invite page (InviteController). JudgeSeeder can also make one fixed
 * account from environment properties.
 * Everything here is inactive until REQUIRE_LOGIN=true.
 */
class AuthController extends Controller
{
    private const MAX_TRIES_PER_ADDRESS = 5;

    private const MAX_TRIES_PER_ACCOUNT = 15;

    private const LOCKOUT_SECONDS = 900;

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

        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        // The route limit counts per IP address. These two count per account, so
        // guesses spread over many addresses still run out: 5 wrong tries per
        // email and address, 15 per email from anywhere, each for 15 minutes.
        $email = $credentials['email'];
        $ip = (string) $request->ip();
        $limits = [
            'login:'.$email.'|'.$ip => self::MAX_TRIES_PER_ADDRESS,
            'login-account:'.$email => self::MAX_TRIES_PER_ACCOUNT,
        ];

        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                Log::warning('Sign-in locked after repeated wrong passwords', ['ip' => $ip, 'email_hash' => hash('sha256', $email)]);

                return back()
                    ->withInput($request->only('email'))
                    ->withErrors(['email' => 'Too many sign-in attempts. Wait 15 minutes, then try again.']);
            }
        }

        // One message for an unknown email and a wrong password, so the
        // form never says which accounts exist.
        if (! Auth::attempt($credentials)) {
            foreach (array_keys($limits) as $key) {
                RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            }

            Log::notice('Failed sign-in', ['ip' => $ip, 'email_hash' => hash('sha256', $email)]);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'The email or password is wrong.']);
        }

        RateLimiter::clear('login:'.$email.'|'.$ip);
        $request->session()->regenerate();
        $request->session()->put(EnforceSessionAge::SESSION_KEY, now()->getTimestamp());
        BindSessionToClient::stamp($request);

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
