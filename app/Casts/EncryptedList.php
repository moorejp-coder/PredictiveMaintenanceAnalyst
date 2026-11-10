<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * A list of strings stored encrypted with APP_KEY.
 *
 * It writes and reads the same format as Laravel's `encrypted:array` cast, so
 * rows already stored stay readable. The difference is the failure case. A
 * stored value that does not decrypt (written before the column was encrypted,
 * or encrypted with an earlier APP_KEY) reads as an empty list and writes one
 * warning to the log. The framework's cast throws instead, and a sign-in with
 * a wrong password then ends in a 500 for that account, because the
 * sign-in code reads this column before it counts the failure.
 *
 * The value is lost, not repaired: the next write replaces it with a list
 * encrypted with the current key.
 *
 * @implements CastsAttributes<list<string>|null, list<string>|null>
 */
final class EncryptedList implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($value), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            Log::warning('A stored encrypted list could not be read and is treated as empty', [
                'model' => $model::class,
                'column' => $key,
                'id' => $model->getKey(),
            ]);

            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        return array_values(array_filter($decoded, is_string(...)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }
}
