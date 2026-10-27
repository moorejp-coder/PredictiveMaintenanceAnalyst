<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OverrideLog;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Only the account that logged an override may view, edit or delete it. A row
 * with no owner (written before user_id existed, or with the sign-in off)
 * belongs to no one, so every user gets 403. The shared-password review page
 * at /overrides is separate and still lists every row.
 */
class OverrideLogPolicy
{
    public function view(User $user, OverrideLog $log): Response
    {
        return $this->owns($user, $log);
    }

    public function update(User $user, OverrideLog $log): Response
    {
        return $this->owns($user, $log);
    }

    public function delete(User $user, OverrideLog $log): Response
    {
        return $this->owns($user, $log);
    }

    private function owns(User $user, OverrideLog $log): Response
    {
        return $log->user_id !== null && (int) $log->user_id === (int) $user->getAuthIdentifier()
            ? Response::allow()
            : Response::deny('You do not own this record.');
    }
}
