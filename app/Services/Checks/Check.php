<?php

declare(strict_types=1);

namespace App\Services\Checks;

/**
 * One failure check. A check owns one limit set, one stress value, one
 * plain-English sentence and the derived numbers it needs.
 *
 * Reading keys: air_temp_k, process_temp_k, rotational_speed_rpm, torque_nm,
 * tool_wear_min, and the optional vibration_mm_s and run_hours.
 */
abstract class Check
{
    /** Driver key shown to the page, such as "heat". */
    abstract public function key(): string;

    /** Driver label shown to the page, such as "Heat buildup". */
    abstract public function label(): string;

    /**
     * Stress from 0 to 1. A value of 1 means the limit is crossed.
     *
     * @param  array<string, float|int>  $reading
     */
    abstract public function stress(array $reading): float;

    /**
     * One sentence for the technician. The deterministic fallback text.
     *
     * @param  array<string, float|int>  $reading
     */
    abstract public function sentence(array $reading): string;

    /**
     * Derived numbers this check owns.
     *
     * @param  array<string, float|int>  $reading
     * @return array<string, float>
     */
    public function metrics(array $reading): array
    {
        return [];
    }

    protected static function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
