<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PersonalDataExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * For a data request that arrives by email: writes the same JSON file the
 * Account page offers (PersonalDataExport), to a file or to the screen.
 */
final class PrismExportAccount extends Command
{
    protected $signature = 'prism:export-account
        {email : Email address of the account.}
        {--output= : Write the JSON to this file instead of the screen.}';

    protected $description = 'Export all data PRISM holds about one account, as JSON';

    public function handle(PersonalDataExport $export): int
    {
        $user = User::where('email', Str::lower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->error('No account has this email address.');

            return self::FAILURE;
        }

        $json = (string) json_encode($export->for($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $output = (string) $this->option('output');
        if ($output === '') {
            $this->line($json);
        } else {
            File::put($output, $json."\n");
            $this->info("Wrote {$output}. Send it to the person only, then delete it.");
        }

        return self::SUCCESS;
    }
}
