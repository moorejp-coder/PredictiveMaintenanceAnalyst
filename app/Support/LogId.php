<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Stand-ins for personal data in log lines (data minimization).
 *
 * email() is a keyed hash: HMAC-SHA-256 with the app key, cut to 16 hex
 * characters. The same address always gives the same value, so log lines
 * about one account still group together. A plain SHA-256 of an email can be
 * matched by anyone who hashes a list of addresses. This one cannot be
 * matched without APP_KEY, which never leaves the server.
 */
final class LogId
{
    public static function email(string $email): string
    {
        return substr(hash_hmac('sha256', Str::lower(trim($email)), (string) config('app.key')), 0, 16);
    }
}
