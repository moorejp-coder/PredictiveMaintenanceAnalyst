<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\AccountLocked;
use App\Rules\NotCommonPassword;
use App\Support\AuditLog;
use App\Support\LogId;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * The page the owner reaches from the "Someone tried to log into your
 * account" email. It offers two choices: change the password, or unlock
 * without changing it. Both are POSTs, so a mail scanner that opens the link
 * changes nothing. Either one ends the lock and spends the link.
 */
class UnlockAccountController extends Controller
{
    private const INVALID = 'This link is not valid. It was already used or it expired. If the account is still locked, it unlocks on its own 30 minutes after the lock began.';

    /**
     * The page the emailed link opens. The token and email ride after "#" in
     * the link, so they never reach the server in the address or its access
     * log. public/js/email-link.js reads them and asks check() about them.
     */
    public function show(): Response
    {
        // same-origin, not no-referrer: the address holds no secret, and with
        // no-referrer browsers send "Origin: null" on the form post, which
        // VerifyRequestOrigin refuses as a cross-site request.
        return response()
            ->view('unlock-account')
            ->header('Referrer-Policy', 'same-origin')
            ->header('Cache-Control', 'no-store');
    }

    /** Is this link good? 200 when it is, 410 for every other case. */
    public function check(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $token = $request->input('token');
        $email = is_string($email) ? Str::lower(trim(mb_substr($email, 0, 255))) : '';
        $token = is_string($token) ? trim(mb_substr($token, 0, 255)) : '';
        $valid = $this->linkUser($email, $token) !== null;

        return response()->json(['valid' => $valid], $valid ? 200 : 410)->header('Cache-Control', 'no-store');
    }

    /** Unlock without changing the password. */
    public function update(Request $request): RedirectResponse
    {
        $data = $this->validateLink($request, []);
        $user = $this->linkUser($data['email'], $data['token']);
        if ($user === null) {
            return redirect('/login')->withErrors(['email' => self::INVALID]);
        }

        $this->finish($user);
        $user->save();
        Log::notice('Account unlocked from the emailed link', ['email_hash' => LogId::email($data['email'])]);
        AuditLog::record('auth.account_unlocked', ['target_user_id' => $user->getKey(), 'email_code' => LogId::email($data['email']), 'password_changed' => false], AuditLog::SUCCESS, $request);

        return redirect('/login')->with('status', 'Your account is unlocked. Sign in with your email and password.');
    }

    /** Change the password. This also unlocks the account. */
    public function changePassword(Request $request): RedirectResponse
    {
        // Same password rules as the set-password page.
        $data = $this->validateLink($request, [
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(12)->max(128)->uncompromised(), new NotCommonPassword],
        ]);
        $user = $this->linkUser($data['email'], $data['token']);
        if ($user === null) {
            return redirect('/login')->withErrors(['email' => self::INVALID]);
        }

        // A new password hash ends every other session of the account. The
        // link came by email, so it also proves the owner reads this inbox.
        $this->finish($user);
        $user->forceFill([
            'password' => $data['password'],
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->setRememberToken(Str::random(60));
        $user->save();
        event(new PasswordReset($user));
        Log::notice('Password changed from the account lock email', ['email_hash' => LogId::email($data['email'])]);
        AuditLog::record('auth.account_unlocked', ['target_user_id' => $user->getKey(), 'email_code' => LogId::email($data['email']), 'password_changed' => true], AuditLog::SUCCESS, $request);

        return redirect('/login')->with('status', 'Your password is changed and your account is unlocked. Sign in with your new password.');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validateLink(Request $request, array $extra): array
    {
        $email = $request->input('email');
        $request->merge(['email' => is_string($email) ? Str::lower(trim($email)) : '']);

        return $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            ...$extra,
        ]);
    }

    private function finish(User $user): void
    {
        $user->clearFailedLogins();
        $user->forceFill(['unlock_token_hash' => null, 'unlock_sent_at' => null]);
    }

    /**
     * The account when the link is good: the token matches the newest link
     * for that email and the link is under 60 minutes old. It still works
     * after the lock ends on its own, so the owner can change the password.
     * Every other case gives null, so the page never says which accounts exist.
     */
    private function linkUser(mixed $email, mixed $token): ?User
    {
        if (! is_string($email) || ! is_string($token) || $email === '' || $token === '') {
            return null;
        }

        $user = User::where('email', $email)->first();
        if ($user === null || $user->unlock_token_hash === null || $user->unlock_sent_at === null) {
            return null;
        }

        if ($user->unlock_sent_at->getTimestamp() < now()->subMinutes(AccountLocked::LINK_MINUTES)->getTimestamp()) {
            return null;
        }

        return hash_equals($user->unlock_token_hash, hash('sha256', $token)) ? $user : null;
    }
}
