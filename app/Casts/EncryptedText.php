<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * A text value stored encrypted with APP_KEY.
 *
 * It writes and reads the same format as Laravel's `encrypted` cast, so rows
 * already stored stay readable. The difference is the failure case. A stored
 * value that does not decrypt (written before the column was encrypted, or
 * encrypted with an earlier APP_KEY) reads as null and writes one warning to
 * the log, with the model, column and id but never the value. The framework's
 * cast throws instead, and every page or export that reads the row then ends
 * in a 500.
 *
 * The stored bytes are not changed by a read. If the earlier key comes back,
 * the value reads again.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedText implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            Log::warning('A stored encrypted text could not be read and is treated as empty', [
                'model' => $model::class,
                'column' => $key,
                'id' => $model->getKey(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString($value);
    }
}
