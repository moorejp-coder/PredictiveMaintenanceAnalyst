<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A short random reference for one failed request. The log line with the full
 * error and the response to the client carry the same value, so it finds the
 * entry. It holds no request data.
 */
final class ErrorReference
{
    public static function for(Request $request): string
    {
        $existing = $request->attributes->get('error_reference');
        if (is_string($existing)) {
            return $existing;
        }

        $reference = Str::lower(Str::random(10));
        $request->attributes->set('error_reference', $reference);

        return $reference;
    }
}
