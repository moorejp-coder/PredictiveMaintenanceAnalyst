<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PersonalData;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * For a deletion request: removes one account, its unused invite links and
 * every override entry it wrote. The admin page /admin/invites does the same.
 */
final class PrismDeleteAccount extends Command
{
    protected $signature = 'prism:delete-account
        {email : Email address of the account.}
        {--force : Skip the confirmation question.}';

    protected $description = 'Delete an account and the override entries it wrote, for good';

    public function handle(PersonalData $data): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();
        if ($user === null) {
            $this->error('No account has this email address.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Delete the account {$email} and every override entry it wrote? This cannot be undone.")) {
            $this->line('Nothing deleted.');

            return self::SUCCESS;
        }

        $result = $data->deleteAccount($user);
        $emailed = $data->sendConfirmation($email, $result);
        $this->info("Account deleted. Override entries: {$result['overrides']}. Invite links: {$result['links']}. Sessions: {$result['sessions']}. Counters: {$result['counters']}. Log lines: {$result['log_lines']}.");
        $this->line($emailed ? 'Confirmation email sent.' : 'The confirmation email could not be sent.');
        $this->line('Logs kept by Elastic Beanstalk are outside the app and were not changed.');

        return self::SUCCESS;
    }
}
