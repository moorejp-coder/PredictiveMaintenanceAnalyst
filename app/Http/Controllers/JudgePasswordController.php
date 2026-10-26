<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * The page a judge reaches from the invite email. The judge chooses a
 * password, the link is spent, and the judge signs in at /login as usual.
 */
class JudgePasswordController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        // The link carries a token, so keep it out of Referer headers and caches.
        return response()
            ->view('set-password', [
                'token' => $token,
                'email' => mb_substr((string) $request->query('email', ''), 0, 255),
            ])
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(12)->max(128)],
        ]);

        $status = Password::broker('invites')->reset(
            [
                'email' => $data['email'],
                'password' => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $data['token'],
            ],
            function (User $user, string $password) {
                // The User model hashes the password on save. Setting it for
                // the first time also proves the judge owns the address.
                $user->forceFill([
                    'password' => $password,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->setRememberToken(Str::random(60));
                $user->save();

                event(new PasswordReset($user));
            },
        );

        if ($status === Password::PasswordReset) {
            return redirect('/login')->with('status', 'Your password is set. Sign in with your email and new password.');
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'This link is not valid. It was already used or it expired. Ask the contest entrant for a new invite.']);
    }
}
