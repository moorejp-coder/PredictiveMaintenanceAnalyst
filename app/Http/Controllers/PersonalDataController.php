<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OverrideLog;
use App\Models\User;
use App\Services\PersonalData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin actions for a deletion request. Both remove rows for good. The routes
 * sit behind authorize.resource:is-admin, so a guest gets 401 and any other
 * account 403. The artisan commands prism:delete-account and
 * prism:erase-overrides do the same from the server.
 */
class PersonalDataController extends Controller
{
    public function __construct(private PersonalData $data) {}

    /** Deletes the account named by email, its invite links and the override entries it wrote. */
    public function deleteAccount(Request $request): RedirectResponse
    {
        $email = $request->input('email');
        $email = is_string($email) ? Str::lower(trim($email)) : '';
        $user = $email === '' ? null : User::where('email', $email)->first();

        if ($user === null) {
            return redirect('/admin/invites')->withErrors(['email' => 'No account has this email address.']);
        }

        // The signed-in admin cannot delete the account in use. An admin
        // account also comes back on the next deploy (AdminSeeder).
        if ((int) $user->getKey() === (int) $request->user()?->getAuthIdentifier()) {
            return redirect('/admin/invites')->withErrors(['email' => 'You cannot delete the account you are signed in with.']);
        }

        $result = $this->data->deleteAccount($user);
        $emailed = $this->data->sendConfirmation($email, $result);

        return redirect('/admin/invites')->with('status', 'Account '.$email.' deleted with '.$result['overrides'].' override entries, '.$result['sessions'].' sessions and '.$result['log_lines'].' log lines. '.($emailed ? 'A confirmation email went to the address.' : 'The confirmation email could not be sent.'));
    }

    /** Erases one override entry for good, soft-deleted or not. */
    public function eraseOverride(OverrideLog $overrideLog): RedirectResponse
    {
        $this->data->eraseOverride($overrideLog);

        return redirect('/overrides')->with('status', 'Entry '.substr((string) $overrideLog->uuid, -8).' erased.');
    }
}
