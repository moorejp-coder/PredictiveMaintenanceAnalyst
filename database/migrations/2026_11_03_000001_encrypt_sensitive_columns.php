<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Encrypts the values already stored in the columns the models now cast
     * as encrypted: override reasons (free text) and the IP addresses of wrong
     * passwords. New values are encrypted by the casts in OverrideLog and
     * User. The key is APP_KEY, which lives in the Elastic Beanstalk
     * environment properties, never in the database or the repo.
     *
     * A value that already decrypts is left as it is, so running this twice
     * changes nothing.
     */
    private const COLUMNS = [
        'override_logs' => ['reason', 'original_reason'],
        'users' => ['failed_login_ips'],
    ];

    public function up(): void
    {
        $this->each(fn (string $value): ?string => self::isEncrypted($value) ? null : Crypt::encryptString($value));
    }

    /** Puts the plain values back, for a rollback. */
    public function down(): void
    {
        $this->each(fn (string $value): ?string => self::isEncrypted($value) ? Crypt::decryptString($value) : null);
    }

    /** @param  callable(string): ?string  $change  Returns the new value, or null to leave the value as it is. */
    private function each(callable $change): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            DB::table($table)->orderBy('id')->each(function (object $row) use ($table, $columns, $change): void {
                $updates = [];
                foreach ($columns as $column) {
                    $value = $row->{$column};
                    if (is_string($value) && $value !== '') {
                        $new = $change($value);
                        if ($new !== null) {
                            $updates[$column] = $new;
                        }
                    }
                }
                if ($updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            });
        }
    }

    private static function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
