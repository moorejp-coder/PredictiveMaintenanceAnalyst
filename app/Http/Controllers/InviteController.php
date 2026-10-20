<?php

namespace App\Http\Controllers;

use App\Models\User;
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
            'users' => User::query()->orderByDesc('created_at')->get(['id', 'name', 'email', 'email_verified_at', 'created_at']),
            'days' => max(1, (int) round(config('auth.passwords.invites.expire') / 1440)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

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
            // account, so a second try after the fix sends the link.
            report($e);

            return back()->withInput($request->only('email', 'name'))->withErrors([
                'email' => 'The email could not be sent. Check the MAIL settings, then try again.',
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
