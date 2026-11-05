<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Where an account leaves traces outside its database rows: session files and
 * PRISM's own log files. The account deletion (PersonalData) and the data
 * export (PersonalDataExport) both use this, so they find the same things.
 */
final class AccountTraces
{
    /**
     * Session files signed in to the account. Empty for other session drivers.
     *
     * @return list<string> File paths.
     */
    public static function sessionFiles(int $userId): array
    {
        $folder = (string) config('session.files');
        if (config('session.driver') !== 'file' || ! is_dir($folder)) {
            return [];
        }

        $key = Auth::guard('web')->getName();
        $found = [];
        foreach (File::files($folder) as $file) {
            $payload = self::readSession((string) file_get_contents($file->getPathname()));
            if (isset($payload[$key]) && (int) $payload[$key] === $userId) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    /** PRISM's own log files. Null config means storage/logs. */
    public static function logFolder(): string
    {
        return (string) (config('prism.log_folder') ?? storage_path('logs'));
    }

    /**
     * The values an email takes in log lines: the keyed hash used now and the
     * plain SHA-256 used before November 3, 2026.
     *
     * @return list<string>
     */
    public static function emailHashes(string $email): array
    {
        return [LogId::email($email), hash('sha256', Str::lower(trim($email)))];
    }

    /** @return array<string, mixed> The session values, or none when the file cannot be read. */
    private static function readSession(string $content): array
    {
        try {
            $json = config('session.encrypt') ? Crypt::decrypt($content) : $content;
        } catch (DecryptException) {
            return [];
        }
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? $data : [];
    }
}
