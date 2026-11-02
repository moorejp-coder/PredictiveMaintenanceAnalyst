<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\NotCommonPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * The page a judge reaches from the invite email. The judge chooses a
 * password, the link is spent, and the judge signs in at /login as usual.
 *
 * Token guessing: the route allows 5 posts and 10 link opens a minute per IP
 * address. On top of that, 5 wrong or spent tokens for one email address in
 * an hour, from any address, stop every try for that email for the rest of
 * the hour (429 with Retry-After). The count runs for unknown emails too, so
 * the answer never says which accounts exist.
 */
class JudgePasswordController extends Controller
{
    public const MAX_BAD_TOKENS_PER_EMAIL = 5;

    private const WINDOW_SECONDS = 3600;

    public function show(Request $request, string $token): Response
    {
        $queryEmail = $request->query('email', '');
        $email = Str::lower(trim(mb_substr(is_string($queryEmail) ? $queryEmail : '', 0, 255)));

        // Check the link before the form appears. An unknown email, a wrong
        // token, a spent token and an expired token all show the same page,
        // so the page never says which accounts exist.
        if ($email !== '') {
            $this->ensureUnder($email);
        }

        $broker = Password::broker('invites');
        $user = $email === '' ? null : $broker->getUser(['email' => $email]);
        $valid = $user !== null && $broker->tokenExists($user, $token);
        if (! $valid && $email !== '') {
            RateLimiter::hit(self::badTokenKey($email), self::WINDOW_SECONDS);
        }

        // The link carries a token, so keep it out of Referer headers and caches.
        return response()
            ->view('set-password', [
                'token' => $valid ? $token : '',
                'email' => $valid ? $email : '',
                'valid' => $valid,
            ], $valid ? 200 : 410)
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): RedirectResponse
    {
        // A non-text value (email[]=a) becomes empty, so validation answers 422.
        $email = $request->input('email');
        $request->merge(['email' => is_string($email) ? Str::lower(trim($email)) : '']);

        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email'],
            // Length is the rule. No symbol or digit is required. A password is
            // refused when it is in the top 10,000 common passwords or has
            // appeared in a known data breach. The breach check asks the Have I
            // Been Pwned range API with the first 5 characters of the SHA-1 hash
            // only. If the API is down, the check is skipped and the password
            // passes, so an outage never locks a judge out.
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(12)->max(128)->uncompromised(), new NotCommonPassword],
        ]);

        $this->ensureUnder($data['email']);

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
            RateLimiter::clear(self::badTokenKey($data['email']));

            return redirect('/login')->with('status', 'Your password is set. Sign in with your email and new password.');
        }

        RateLimiter::hit(self::badTokenKey($data['email']), self::WINDOW_SECONDS);

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'This link is not valid. It was already used or it expired. Ask the contest entrant for a new invite.']);
    }

    private static function badTokenKey(string $email): string
    {
        return 'reset-bad-tokens:'.hash('sha256', $email);
    }

    private function ensureUnder(string $email): void
    {
        $key = self::badTokenKey($email);
        if (RateLimiter::tooManyAttempts($key, self::MAX_BAD_TOKENS_PER_EMAIL)) {
            throw new ThrottleRequestsException('Too many tries with this link. Wait an hour, then try again.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
        }
    }
}
