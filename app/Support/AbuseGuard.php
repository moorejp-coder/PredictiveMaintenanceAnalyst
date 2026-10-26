<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Spots input that looks like an attack. Used by the RejectAbuse middleware.
 *
 * The patterns need real attack syntax, not a lone word. A reason such as
 * "Please select the next part" passes. "x' UNION SELECT password FROM users"
 * does not. Parameterized queries and output escaping stay the real defense;
 * this layer rejects and logs the obvious attempts.
 */
final class AbuseGuard
{
    /** The hidden form field. A person never sees it, so a person leaves it empty. */
    public const HONEYPOT = 'homepage';

    /** Longest text any field may hold. The longest allowed field is 500 characters. */
    public const MAX_LENGTH = 1000;

    /** Fields that are never scanned or logged: secrets and random tokens. */
    public const SECRET_FIELDS = ['password', 'password_confirmation', 'token', '_token'];

    private const PATTERNS = [
        'script_tag' => '/<\s*\/?\s*(script|iframe|object|embed)\b/i',
        'javascript_url' => '/\b(javascript|vbscript)\s*:/i',
        'event_handler' => '/\bon(error|load|click|mouseover|mouseenter|focus|blur|submit|toggle|animationstart)\s*=/i',
        'sql_union' => '/\bunion\b\s+(all\s+)?select\b/i',
        'sql_stacked' => '/;\s*(drop|delete|truncate|alter|insert|update|create|exec)\b\s+\w/i',
        'sql_drop_table' => '/\b(drop|truncate)\s+(table|database)\b/i',
        'sql_delete' => '/\bdelete\s+from\s+[\w.`"\[\]]+\s*(where\b|;|$)/i',
        'sql_insert' => '/\binsert\s+into\s+[\w.`"\[\]]+\s*(\(|values\b|select\b)/i',
        'sql_tautology' => '/[\'"]\s*(or|and)\s+([\'"]?)(\w+)\2\s*=\s*\2\3\2?/i',
        'sql_numeric_tautology' => '/\b(or|and)\s+(\d+)\s*=\s*\2\b/i',
        'sql_quote_comment' => '/[\'"]\s*--/',
        'sql_time_delay' => '/\b(sleep|benchmark|pg_sleep)\s*\(\s*\d|\bwaitfor\s+delay\b/i',
        'sql_system' => '/\b(information_schema|xp_cmdshell|load_file\s*\()/i',
    ];

    /**
     * Name of the first attack pattern the text matches, or null.
     * Reports "too_long" for a string over MAX_LENGTH characters.
     */
    public static function check(string $value): ?string
    {
        if (mb_strlen($value) > self::MAX_LENGTH) {
            return 'too_long';
        }

        // Look at the text the way a database or a browser would: decode
        // HTML entities and treat a SQL block comment as a space.
        $probe = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $probe = preg_replace('/\/\*.*?\*\//s', ' ', $probe) ?? $probe;
        $probe = preg_replace('/\s+/u', ' ', $probe) ?? $probe;

        foreach (self::PATTERNS as $name => $regex) {
            if (preg_match($regex, $probe) === 1 || preg_match($regex, $value) === 1) {
                return $name;
            }
        }

        return null;
    }

    /** A short, single-line piece of text that is safe to write to a log. */
    public static function excerpt(string $value): string
    {
        $clean = preg_replace('/[\p{C}]+/u', ' ', $value) ?? '';

        return Str::limit($clean, 100, '...');
    }
}
