<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Loads config/prism.php as a plain array.
 *
 * Laravel also reads the same file as config('prism'). This loader exists so
 * the scoring classes and their unit tests work without booting the app.
 */
final class Limits
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::$cache ??= require dirname(__DIR__, 2).'/config/prism.php';
    }
}
