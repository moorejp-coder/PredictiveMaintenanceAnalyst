<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PlainText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The entrant's invite page. Typing an email creates an account with a random
 * password nobody knows, then emails the judge a link to choose a password.
 * Typing the email of an existing account sends a fresh link instead.
 */
class InviteController extends Controller
{
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

        $user = User::firstOrCreate(
            ['email' => $data['email']],
            ['name' => trim((string) ($data['name'] ?? '')) ?: 'Contest Judge', 'password' => Str::random(64)],
        );

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
}
