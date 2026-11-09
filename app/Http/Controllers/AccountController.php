<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PersonalData;
use App\Services\PersonalDataExport;
use App\Support\AuditLog;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The signed-in user's account page. One action: sign out of every other
 * browser. The routes sit behind the sign-in switch (EnsureLoginEnabled) and
 * the auth middleware, so a demo visitor never reaches them.
 */
class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('account', ['email' => $this->currentUser($request)->email]);
    }

    public function logoutEverywhere(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = $this->currentUser($request);

        if (! Hash::check($data['password'], $user->getAuthPassword())) {
            return back()->withErrors(['password' => 'The password is wrong.']);
        }

        // Saves a fresh hash of the same password. Every other session holds
        // the old hash, so AuthenticateSession ends each one on its next
        // request. This browser keeps its session. The password itself does
        // not change, so the audit log gets a sessions_revoked line and not
        // a password_changed one (User::booted).
        $request->attributes->set(AuditLog::SKIP_PASSWORD_EVENT, true);
        Auth::logoutOtherDevices($data['password']);
        AuditLog::record('account.sessions_revoked', ['target_user_id' => $user->getKey()], AuditLog::SUCCESS, $request);

        return redirect('/account')->with('status', 'Every other browser is signed out. This one stays signed in.');
    }

    /**
     * Downloads everything PRISM holds about the signed-in account as one
     * JSON file (PersonalDataExport). The password confirms the request, so
     * a stolen session alone cannot take the data.
     */
    public function export(Request $request, PersonalDataExport $export): JsonResponse|RedirectResponse
    {
        $request->validateWithBag('export', ['password' => ['required', 'string', 'max:255']]);
        $user = $this->currentUser($request);

        if (! Hash::check((string) $request->input('password'), $user->getAuthPassword())) {
            return back()->withErrors(['password' => 'The password is wrong.'], 'export');
        }

        Log::notice('Personal data exported', ['user_id' => $user->getAuthIdentifier()]);
        AuditLog::record('data.export', ['scope' => 'own_account', 'target_user_id' => $user->getKey()], AuditLog::SUCCESS, $request);

        return response()
            ->json($export->for($user), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Disposition', 'attachment; filename="prism-data-export-'.now()->utc()->format('Y-m-d').'.json"')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Deletes the signed-in account and everything tied to it, for good
     * (PersonalData::deleteAccount), then emails a confirmation and shows
     * what was removed. The password and a ticked box confirm the request.
     */
    public function deleteAccount(Request $request, PersonalData $data): RedirectResponse|JsonResponse
    {
        $request->validateWithBag('delete', [
            'password' => ['required', 'string', 'max:255'],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Tick the box to confirm that the deletion cannot be undone.',
        ]);

        $user = $this->currentUser($request);

        if (! Hash::check((string) $request->input('password'), $user->getAuthPassword())) {
            return back()->withErrors(['password' => 'The password is wrong.'], 'delete');
        }

        // An admin account comes from ADMIN_EMAIL and the deploy recreates it,
        // so deleting it here would not last.
        if ($user->isAdmin()) {
            return back()->withErrors(['password' => 'This is the admin account set by ADMIN_EMAIL. Remove ADMIN_EMAIL in the Elastic Beanstalk console instead.'], 'delete');
        }

        $email = (string) $user->email;
        $removed = $data->deleteAccount($user);
        $emailed = $data->sendConfirmation($email, $removed);

        // logoutCurrentDevice, not logout: logout saves the user to change
        // its remember token, which would write the deleted row back.
        Auth::logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['deleted' => true, 'removed' => $removed, 'confirmation_email_sent' => $emailed]);
        }

        return redirect('/account/deleted')->with('deletion', ['removed' => $removed, 'emailed' => $emailed]);
    }

    /** The routes sit behind the auth middleware, so a missing user is an error, not a normal case. */
    private function currentUser(Request $request): User
    {
        return $request->user() ?? throw new AuthenticationException;
    }
}
