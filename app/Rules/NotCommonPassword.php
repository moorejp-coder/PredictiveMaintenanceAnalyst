<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a password found in the 10,000 most common passwords
 * (resources/data/common-passwords.txt, the SecLists "10k-most-common" list).
 * The check is local, so it needs no network and runs the same in every
 * environment. Case is ignored: "Password" matches "password". The wider
 * data-breach check is Laravel's uncompromised() rule, which asks the Have I
 * Been Pwned range API (see JudgePasswordController).
 */
class NotCommonPassword implements ValidationRule
{
    /** @var array<string, true>|null */
    private static ?array $common = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && isset(self::common()[mb_strtolower($value)])) {
            $fail('That password is on a list of the most common passwords. Choose a different one.');
        }
    }

    /** @return array<string, true> */
    private static function common(): array
    {
        if (self::$common === null) {
            self::$common = [];
            $lines = file(resource_path('data/common-passwords.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            foreach ($lines as $line) {
                self::$common[mb_strtolower(trim($line))] = true;
            }
        }

        return self::$common;
    }
}
