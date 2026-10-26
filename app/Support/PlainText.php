<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cleans text a visitor typed before it is validated and stored.
 *
 * The text stays plain text. HTML characters are kept as typed and are
 * escaped when a page shows them (Blade {{ }}). This class removes only what
 * has no place in a name or a reason: control characters, such as the null
 * byte, and bytes that are not valid UTF-8. Anything that is not a string is
 * returned unchanged, so the "string" validation rule still rejects it.
 */
final class PlainText
{
    /** One line of text, such as a name. Line breaks and tabs become spaces. */
    public static function line(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $clean = $clean === null ? null : preg_replace('/\s+/u', ' ', $clean);

        return $clean === null ? null : trim($clean);
    }

    /** Several lines of text, such as a reason. Line breaks and tabs stay. */
    public static function text(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $value));

        return $clean === null ? null : trim($clean);
    }
}
