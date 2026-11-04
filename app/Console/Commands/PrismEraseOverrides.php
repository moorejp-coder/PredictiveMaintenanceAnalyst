<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OverrideLog;
use App\Models\User;
use App\Services\PersonalData;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * For a deletion request: removes override entries for good, by the account
 * that wrote them (the account itself stays) or by entry id. An entry saved
 * with the sign-in off has no account, so only its id finds it. The admin
 * page /overrides erases one entry.
 */
final class PrismEraseOverrides extends Command
{
    protected $signature = 'prism:erase-overrides
        {--account= : Erase every entry written by the account with this email. The account stays.}
        {--id=* : Erase the entry with this id (the full id, or the 8 characters shown on /overrides). Repeat for several.}
        {--force : Skip the confirmation question.}';

    protected $description = 'Erase override log entries for good, by account or entry id';

    public function handle(PersonalData $data): int
    {
        $account = Str::lower(trim((string) $this->option('account')));
        $ids = array_values(array_filter(array_map(fn (mixed $id): string => is_string($id) ? trim($id) : '', (array) $this->option('id')), fn (string $id): bool => $id !== ''));

        if (($account === '') === ($ids === [])) {
            $this->error('Give either --account or --id.');

            return self::FAILURE;
        }

        if ($account !== '') {
            $user = User::where('email', $account)->first();
            if ($user === null) {
                $this->error('No account has this email address.');

                return self::FAILURE;
            }
            $query = $data->overridesOf($user);
        } else {
            foreach ($ids as $id) {
                if (preg_match('/^[0-9a-f-]{8,36}$/i', $id) !== 1) {
                    $this->error("Not an entry id: {$id}");

                    return self::FAILURE;
                }
            }
            $query = OverrideLog::withTrashed()->where(function ($q) use ($ids): void {
                foreach ($ids as $id) {
                    strlen($id) === 36 ? $q->orWhere('uuid', strtolower($id)) : $q->orWhere('uuid', 'like', '%'.strtolower($id));
                }
            });
        }

        $count = (clone $query)->count();
        if ($count === 0) {
            $this->line('No entry matches. Nothing erased.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Erase {$count} override ".($count === 1 ? 'entry' : 'entries').' for good? This cannot be undone.')) {
            $this->line('Nothing erased.');

            return self::SUCCESS;
        }

        $erased = $data->eraseOverrides($query);
        $this->info("Erased {$erased} override ".($erased === 1 ? 'entry' : 'entries').'.');

        return self::SUCCESS;
    }
}
