<?php

declare(strict_types=1);

namespace App\Logging;

use Stringable;
use Throwable;

/**
 * Replaces secrets in log text and log context with masked values.
 *
 * Nothing in the app logs a password, a token or a request body on purpose.
 * This class is the net under that rule: an exception message that carries an
 * inlined SQL value, a mail transport error with a password in a DSN, or a
 * future log call that passes the wrong array is cleaned before it is written.
 *
 * What it does, and the mask each value gets:
 *   password hashes (bcrypt, argon2)        [REDACTED_HASH]
 *   values under a secret key name          [REDACTED], or prefix and last four
 *                                           for API keys and tokens (sk_live_****1234)
 *   session and cookie values               [REDACTED], never partial
 *   Authorization, Cookie, Set-Cookie       header value replaced
 *   bearer tokens, JWTs, vendor API keys    prefix and last four
 *   card numbers (13 to 19 digits, Luhn)    ****-****-****-1234
 *   Social Security numbers (123-45-6789)   ***-**-6789
 *   URL passwords and token query values    masked in place
 *   SQL with inlined values in an error     SQL text replaced
 *
 * Limits: a nine digit number with no dashes is not treated as a Social
 * Security number unless it sits under a key such as "ssn", because the
 * pattern would hit ids and counters. A secret in free text with no key name
 * and no known shape cannot be found.
 */
final class Redactor
{
    public const REDACTED = '[REDACTED]';

    public const HASH = '[REDACTED_HASH]';

    /** Key names (whole words, split on _ - . and camelCase) whose value is never logged. */
    private const SECRET_KEY = '/(?:^|[_\-.\s])(?:password|passwd|pwd|passphrase|secret|token|api[_-]?key|apikey|authorization|cookie|set[_-]?cookie|session|sessid|csrf|xsrf|remember|cvv|cvc|ssn|credential|credentials|private[_-]?key)(?:$|[_\-.\s])/i';

    /** Key names whose value is a session or cookie, which are masked in full. */
    private const FULL_KEY = '/(?:^|[_\-.\s])(?:password|passwd|pwd|passphrase|cookie|set[_-]?cookie|session|sessid|csrf|xsrf|remember|cvv|cvc|ssn|credential|credentials|private[_-]?key|authorization)(?:$|[_\-.\s])/i';

    private const MAX_DEPTH = 8;

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function context(array $data, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['[too deep]'];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            $name = (string) $key;

            if ($value instanceof Throwable) {
                // The formatter prints the exception. It cleans the final text.
                $clean[$key] = $value;
            } elseif (is_scalar($value) && self::isSecretKey($name) && $value !== '') {
                $clean[$key] = self::maskKeyed($name, (string) $value);
            } elseif (is_array($value)) {
                $clean[$key] = self::isSecretKey($name) && ! self::isList($value)
                    ? self::REDACTED
                    : self::context($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$key] = self::text($value);
            } elseif ($value instanceof Stringable) {
                $clean[$key] = self::text((string) $value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    public static function text(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // Header lines first, so the value after the name is gone before the
        // shape patterns look at it.
        $text = (string) preg_replace(
            '/\b(Authorization|Proxy-Authorization|Cookie|Set-Cookie|X-XSRF-TOKEN|X-CSRF-TOKEN|X-Api-Key)(\\\\?["\']?\s*[:=]\s*\\\\?["\']?)[^\r\n"\\\\]+/i',
            '$1$2'.self::REDACTED,
            $text,
        );

        // A password hash. $2y$ bcrypt and $argon2id$ forms.
        $text = (string) preg_replace('/\$2[abxy]\$\d{2}\$[.\/A-Za-z0-9]{53}/', self::HASH, $text);
        $text = (string) preg_replace('/\$argon2(?:id|i|d)\$[^\s"\'\\\\,)]+/', self::HASH, $text);

        // key=value and "key":"value" forms with a secret key name.
        $text = (string) preg_replace_callback(
            '/(?<![A-Za-z0-9])(["\']?\\\\?["\']?)((?:[A-Za-z0-9]+[_\-.])*(?:password|passwd|pwd|passphrase|secret|token|api[_-]?key|apikey|session|sessid|csrf|xsrf|remember|cvv|cvc|ssn|private[_-]?key)(?:[_\-.][A-Za-z0-9]+)*)(\\\\?["\']?\s*[:=]\s*\\\\?["\']?)([^\s"\'\\\\&,;})\]]+)/i',
            static function (array $m): string {
                if (self::isIdentifier($m[4])) {
                    return $m[0];
                }

                return $m[1].$m[2].$m[3].self::maskKeyed($m[2], $m[4]);
            },
            $text,
        );

        // Bearer tokens.
        $text = (string) preg_replace_callback(
            '/\b(Bearer\s+)([A-Za-z0-9._~+\/=-]{8,})/i',
            static fn (array $m): string => $m[1].self::partial($m[2]),
            $text,
        );

        // Vendor key shapes: Stripe, Anthropic, OpenAI, AWS, GitHub, Slack.
        $text = (string) preg_replace_callback(
            '/\b(?:(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{8,}|sk-ant-[A-Za-z0-9_-]{10,}|sk-[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|xox[abprs]-[A-Za-z0-9-]{10,})\b/',
            static fn (array $m): string => self::partial($m[0]),
            $text,
        );

        // Slack incoming webhook URLs. The path is the secret.
        $text = (string) preg_replace(
            '#(https://hooks\.slack\.com/services/)[A-Za-z0-9/]+#i',
            '$1'.self::REDACTED,
            $text,
        );

        // JSON web tokens.
        $text = (string) preg_replace('/\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', self::REDACTED, $text);

        // A password in a URL: scheme://user:password@host.
        $text = (string) preg_replace('#(://[^\s:/@"\']+:)[^\s@/"\']+(@)#', '$1****$2', $text);

        // A secret in a query string.
        $text = (string) preg_replace_callback(
            '/([?&](?:token|api[_-]?key|apikey|access[_-]?token|auth|signature|password|secret|key)=)([^&\s"\'\\\\]+)/i',
            static fn (array $m): string => $m[1].self::partial($m[2]),
            $text,
        );

        // SQL that an exception message carries with its values inlined.
        $text = (string) preg_replace('/\bSQL: .*?\)(?= at \S|\\\\n|\r?\n|$)/m', 'SQL: '.self::REDACTED.')', $text);

        // Social Security numbers written with dashes.
        $text = (string) preg_replace_callback(
            '/(?<![\d-])(?!000|666|9\d\d)\d{3}-(?!00)\d{2}-(?!0000)(\d{4})(?![\d-])/',
            static fn (array $m): string => '***-**-'.$m[1],
            $text,
        );

        // Card numbers: 13 to 19 digits, spaces or dashes allowed, Luhn valid.
        return (string) preg_replace_callback(
            '/(?<![\d.])(?:\d[ -]?){12,18}\d(?![\d])/',
            static function (array $m): string {
                $digits = (string) preg_replace('/\D/', '', $m[0]);

                if (strlen($digits) < 13 || strlen($digits) > 19 || ! self::luhn($digits)) {
                    return $m[0];
                }

                return '****-****-****-'.substr($digits, -4);
            },
            $text,
        );
    }

    /** A value with its start and last four characters kept: sk_live_****1234. */
    public static function partial(string $value): string
    {
        $length = strlen($value);

        if ($length < 12) {
            return self::REDACTED;
        }

        $prefix = preg_match('/^((?:[A-Za-z]{2,}[_-])+(?:(?:live|test|ant|api\d*)[_-])?)/', $value, $m) === 1 ? $m[1] : '';

        return $prefix.'****'.substr($value, -4);
    }

    private static function maskKeyed(string $key, string $value): string
    {
        if (preg_match(self::FULL_KEY, self::spaced($key)) === 1) {
            return self::REDACTED;
        }

        return self::partial($value);
    }

    private static function isSecretKey(string $key): bool
    {
        return preg_match(self::SECRET_KEY, self::spaced($key)) === 1;
    }

    /** fooBarToken becomes foo_Bar_Token, so camelCase keys match whole words. */
    private static function spaced(string $key): string
    {
        return (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key);
    }

    /** @param  array<array-key, mixed>  $value */
    private static function isList(array $value): bool
    {
        return array_is_list($value);
    }

    /** A value that is plainly not a secret: a bare number or a mask already applied. */
    private static function isIdentifier(string $value): bool
    {
        return $value === self::REDACTED
            || str_contains($value, '****')
            || str_starts_with($value, '[REDACTED')
            || ctype_digit($value) && strlen($value) < 10;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $alternate = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];

            if ($alternate) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }

            $sum += $n;
            $alternate = ! $alternate;
        }

        return $sum % 10 === 0;
    }
}
