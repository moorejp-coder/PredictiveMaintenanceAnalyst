<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\BindSessionToClient;
use App\Http\Middleware\EnforceSessionAge;
use App\Models\User;
use App\Notifications\AccountLocked;
use App\Support\Alerts;
use App\Support\AuditLog;
use App\Support\EmailBudget;
use App\Support\LogId;
use App\Support\LoginOrigins;
use App\Support\SafeRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;
use Throwable;

/**
 * Sign in and sign out. PRISM has no sign-up page. Judge accounts come from
 * the invite page (InviteController). JudgeSeeder can also make one fixed
 * account from environment properties.
 * Everything here is inactive until REQUIRE_LOGIN=true.
 *
 * Two locks guard the form:
 * - Per IP address: 5 failed sign-ins within 15 minutes block that address
 *   for 15 minutes, whatever email it tries.
 * - Per account: 10 wrong passwords in a row, from any address, lock the
 *   account for 30 minutes and email the owner the time of the tries, the
 *   IP addresses and a link to change the password. The lock ends after 30
 *   minutes or when the owner uses the link.
 */
class AuthController extends Controller
{
    /** The one answer for an unknown email, a wrong password and a locked account. */
    public const INVALID = 'Invalid email or password';

    public const ADDRESS_BLOCKED = 'Too many sign-in attempts. Wait 15 minutes, then try again.';

    public const MAX_FAILURES_PER_ADDRESS = 5;

    public const MAX_FAILURES_PER_ACCOUNT = 10;

    private const ADDRESS_BLOCK_SECONDS = 900;

    /** How many IP addresses one wrong-password run keeps for the email. */
    private const MAX_IPS_KEPT = 10;

    public function show(): View|RedirectResponse
    {
        // Switched off: there is nothing to sign in to.
        if (! config('prism.require_login')) {
            return redirect('/');
        }

        return view('login');
    }

    public function login(Request $request): RedirectResponse|Response
    {
        abort_unless(config('prism.require_login'), 404);

        // A non-text value (email[]=a) becomes empty, so validation answers 422.
        $email = $request->input('email');
        $request->merge(['email' => is_string($email) ? Str::lower(trim($email)) : '']);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $email = $credentials['email'];
        $ip = (string) $request->ip();

        $blockedUntil = Cache::get(self::blockKey($ip));
        if (is_int($blockedUntil) && $blockedUntil > now()->getTimestamp()) {
            Log::warning('Sign-in refused from a blocked address', ['ip' => $ip]);
            AuditLog::record('auth.login.failed', ['reason' => 'address_blocked', 'email_code' => LogId::email($email)], AuditLog::FAILURE, $request);
            Alerts::failedLogin($email, $request);

            return $this->blocked($request, $blockedUntil - now()->getTimestamp());
        }

        $user = User::where('email', $email)->first();

        // A lock older than 30 minutes has ended: start a fresh count. The
        // emailed link keeps working until it expires.
        if ($user !== null && $user->locked_at !== null && ! $user->isLocked()) {
            $user->clearFailedLogins();
            $user->save();
        }

        // A locked account refuses even the right password, with the same
        // message as a wrong one, so the form never says the account exists
        // or is locked.
        if ($user !== null && $user->isLocked()) {
            Hash::check($credentials['password'], $user->getAuthPassword());
            $this->recordAddressFailure($ip);
            Log::notice('Sign-in refused for a locked account', ['ip' => $ip, 'email_hash' => LogId::email($email)]);
            AuditLog::record('auth.login.failed', ['reason' => 'account_locked', 'email_code' => LogId::email($email), 'target_user_id' => $user->getKey()], AuditLog::FAILURE, $request);
            Alerts::failedLogin($email, $request);

            return $this->refuse($request, self::INVALID);
        }

        // An invited account is not active until its owner opens the emailed
        // link, which verifies the email. Same answer as a wrong password.
        if ($user !== null && $user->email_verified_at === null) {
            Hash::check($credentials['password'], $user->getAuthPassword());
            $this->recordAddressFailure($ip);
            Log::notice('Sign-in refused for an unverified account', ['ip' => $ip, 'email_hash' => LogId::email($email)]);
            AuditLog::record('auth.login.failed', ['reason' => 'account_unverified', 'email_code' => LogId::email($email), 'target_user_id' => $user->getKey()], AuditLog::FAILURE, $request);
            Alerts::failedLogin($email, $request);

            return $this->refuse($request, self::INVALID);
        }

        // Laravel can save a fresh hash of the same password during a good
        // sign-in (when the hashing settings changed). That is not a password
        // change by a person, so the audit log skips it (User::booted).
        $request->attributes->set(AuditLog::SKIP_PASSWORD_EVENT, true);

        if (! Auth::attempt($credentials)) {
            // Laravel skips the hash check for an unknown email. Run one
            // anyway so the answer takes as long as a wrong password does.
            if ($user === null) {
                Hash::check($credentials['password'], self::dummyHash());
            }

            $this->recordAddressFailure($ip);
            if ($user !== null) {
                $this->recordAccountFailure($user, $ip);
            }
            Log::notice('Failed sign-in', ['ip' => $ip, 'email_hash' => LogId::email($email)]);
            AuditLog::record('auth.login.failed', [
                'reason' => $user === null ? 'unknown_email' : 'wrong_password',
                'email_code' => LogId::email($email),
                'target_user_id' => $user?->getKey(),
            ], AuditLog::FAILURE, $request);
            Alerts::failedLogin($email, $request);

            return $this->refuse($request, self::INVALID);
        }

        // A good sign-in resets the account count. The address count stays,
        // so one known account cannot be used to reset guesses at others.
        $signedIn = Auth::user();
        if ($signedIn instanceof User && $signedIn->failed_login_attempts > 0) {
            $signedIn->clearFailedLogins();
            $signedIn->save();
        }

        $request->session()->regenerate();
        $request->session()->put(EnforceSessionAge::SESSION_KEY, now()->getTimestamp());
        BindSessionToClient::stamp($request);
        AuditLog::record('auth.login.success', ['email_code' => LogId::email($email)], AuditLog::SUCCESS, $request);
        if ($signedIn instanceof User) {
            LoginOrigins::observe($signedIn, $request);
        }

        // The page the guest asked for before sign-in, if it is on PRISM.
        return redirect(SafeRedirect::path($request->session()->pull('url.intended'), '/'));
    }

    public function logout(Request $request): RedirectResponse
    {
        abort_unless(config('prism.require_login'), 404);

        AuditLog::record('auth.logout', [], AuditLog::SUCCESS, $request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public static function blockKey(string $ip): string
    {
        return 'login-blocked:'.$ip;
    }

    /**
     * 429 Too Many Requests with Retry-After, like every other limit in PRISM.
     * The sign-in page itself comes back, with the wait as its error.
     */
    private function blocked(Request $request, int $seconds): Response
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['email' => [self::ADDRESS_BLOCKED]]));
        $request->session()->flashInput($request->only('email'));

        return response()
            ->view('login', ['errors' => $errors], 429)
            ->header('Retry-After', (string) max(1, $seconds));
    }

    private function refuse(Request $request, string $message): RedirectResponse
    {
        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => $message]);
    }

    /** 5 failures within 15 minutes block the address for the next 15 minutes. */
    private function recordAddressFailure(string $ip): void
    {
        $key = 'login-failures:'.$ip;
        RateLimiter::hit($key, self::ADDRESS_BLOCK_SECONDS);

        if (RateLimiter::attempts($key) >= self::MAX_FAILURES_PER_ADDRESS) {
            Cache::put(self::blockKey($ip), now()->getTimestamp() + self::ADDRESS_BLOCK_SECONDS, self::ADDRESS_BLOCK_SECONDS);
            RateLimiter::clear($key);
            Log::warning('Address blocked for 15 minutes after repeated failed sign-ins', ['ip' => $ip]);
            AuditLog::record('auth.address_blocked', ['minutes' => self::ADDRESS_BLOCK_SECONDS / 60, 'failures' => self::MAX_FAILURES_PER_ADDRESS], AuditLog::ALERT);
        }
    }

    /** The tenth wrong password in a row locks the account and emails the owner. */
    private function recordAccountFailure(User $user, string $ip): void
    {
        $ips = $user->failed_login_ips ?? [];
        if (! in_array($ip, $ips, true) && count($ips) < self::MAX_IPS_KEPT) {
            $ips[] = $ip;
        }

        $user->forceFill([
            'failed_login_attempts' => $user->failed_login_attempts + 1,
            'first_failed_login_at' => $user->first_failed_login_at ?? now(),
            'failed_login_ips' => $ips,
        ])->save();

        if ($user->failed_login_attempts >= self::MAX_FAILURES_PER_ACCOUNT) {
            $user->forceFill(['locked_at' => now()])->save();
            Log::warning('Account locked for 30 minutes after repeated wrong passwords', ['email_hash' => LogId::email((string) $user->email)]);
            AuditLog::record('auth.account_locked', ['target_user_id' => $user->getKey(), 'email_code' => LogId::email((string) $user->email), 'minutes' => User::LOCK_MINUTES, 'failures' => self::MAX_FAILURES_PER_ACCOUNT], AuditLog::ALERT);
            $this->sendLockEmail($user);
        }
    }

    /** A new token replaces any older one, so only the newest link works. */
    private function sendLockEmail(User $user): void
    {
        $token = Str::random(64);
        $user->forceFill([
            'unlock_token_hash' => hash('sha256', $token),
            'unlock_sent_at' => now(),
        ])->save();

        $lockedAt = $user->locked_at ?? now();

        // The lock still applies when the site-wide email cap is reached.
        // One account gets at most two lock emails an hour, because each lock
        // lasts 30 minutes.
        if (! EmailBudget::available()) {
            Log::error('Account lock email skipped: daily email cap reached', ['email_hash' => LogId::email((string) $user->email)]);
            $user->forceFill(['failed_login_ips' => null])->save();

            return;
        }
        EmailBudget::record();

        // A mail outage must not change the answer the form gives.
        try {
            $user->notify(new AccountLocked($token, $user->first_failed_login_at ?? $lockedAt, $lockedAt, $user->failed_login_ips ?? []));
        } catch (Throwable $e) {
            Log::error('Could not send the account lock email', ['email_hash' => LogId::email((string) $user->email), 'error' => $e->getMessage()]);
        }

        // The IP addresses exist only to go into this email. Once it is
        // built, the account stops keeping them (data minimization).
        $user->forceFill(['failed_login_ips' => null])->save();
    }

    /** A hash made with the app's own hasher settings, so its check costs the same. */
    private static function dummyHash(): string
    {
        return (string) Cache::rememberForever('login-dummy-hash', fn (): string => Hash::make(Str::random(40)));
    }
}
