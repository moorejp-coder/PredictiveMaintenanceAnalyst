<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\EmailBudget;
use App\Support\PlainText;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The entrant's invite page. Typing an email creates an account with a random
 * password nobody knows, then emails the judge a link to choose a password.
 * Typing the email of an existing account sends a fresh link instead.
 *
 * Abuse limits, on top of the route limit of 10 invites per hour per admin
 * (throttle:invites) and the site-wide email cap (EmailBudget):
 * - 3 new accounts per IP address per hour.
 * - 3 set-password emails per email address per hour.
 * Over either limit the answer is 429 with Retry-After.
 * A new account cannot sign in until its owner opens the emailed link,
 * which verifies the email (JudgePasswordController).
 */
class InviteController extends Controller
{
    public const MAX_NEW_ACCOUNTS_PER_ADDRESS = 3;

    public const MAX_LINKS_PER_EMAIL = 3;

    private const WINDOW_SECONDS = 3600;

    public function index(): View
    {
        return view('admin.invites', [
            'users' => User::query()->orderByDesc('created_at')->get(['name', 'email', 'email_verified_at', 'created_at']),
            'minutes' => max(1, (int) config('auth.passwords.invites.expire')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // A non-text value (email[]=a) becomes empty, so validation answers 422.
        $email = $request->input('email');
        $request->merge([
            'email' => is_string($email) ? Str::lower(trim($email)) : '',
            'name' => PlainText::line($request->input('name')),
        ]);

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $emailKey = 'invite-links:'.hash('sha256', $data['email']);
        $this->ensureUnder($emailKey, self::MAX_LINKS_PER_EMAIL, 'Too many invite emails to this address. Wait an hour, then try again.');

        if (! EmailBudget::available()) {
            throw new ThrottleRequestsException('PRISM has sent its most emails for today. Try again later.', null, ['Retry-After' => EmailBudget::retryAfter()]);
        }

        $user = User::where('email', $data['email'])->first();
        if ($user === null) {
            $addressKey = 'signups:'.$request->ip();
            $this->ensureUnder($addressKey, self::MAX_NEW_ACCOUNTS_PER_ADDRESS, 'Too many new accounts from this network. Wait an hour, then try again.');

            $user = User::create([
                'email' => $data['email'],
                'name' => trim((string) ($data['name'] ?? '')) ?: 'Contest Judge',
                'password' => Str::random(64),
            ]);
            RateLimiter::hit($addressKey, self::WINDOW_SECONDS);
        }

        // Counted before the send, so a failing mail service still uses up tries.
        RateLimiter::hit($emailKey, self::WINDOW_SECONDS);
        EmailBudget::record();

        try {
            $status = Password::broker('invites')->sendResetLink(['email' => $user->email]);
        } catch (\Throwable $e) {
            // Mail settings missing or the mail service refused. Keep the
            // account, so a second try after the fix sends the link. The
            // full error, with its trace, goes to the server log only.
            report($e);

            return back()->withInput($request->only('email', 'name'))->withErrors([
                'email' => 'The invite could not be sent right now. Try again in a few minutes.',
            ]);
        }

        if ($status === Password::ResetThrottled) {
            return back()->withInput($request->only('email', 'name'))->withErrors([
                'email' => 'An invite went to '.$user->email.' less than a minute ago. Wait, then try again.',
            ]);
        }

        if ($status !== Password::ResetLinkSent) {
            return back()->withInput($request->only('email', 'name'))->withErrors([
                'email' => 'The invite was not sent.',
            ]);
        }

        return back()->with('status', 'Invite sent to '.$user->email.'.');
    }

    private function ensureUnder(string $key, int $max, string $message): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ThrottleRequestsException($message, null, ['Retry-After' => RateLimiter::availableIn($key)]);
        }
    }
}
