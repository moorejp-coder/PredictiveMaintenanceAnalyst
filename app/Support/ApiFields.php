<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The exact fields each JSON endpoint may send to the browser.
 *
 * A response is built from a service result or a model, and those hold more
 * than the page needs. pick() copies only the fields named in a shape and drops
 * the rest, at every level. A field added to a service later stays out of the
 * response until it is added here on purpose.
 *
 * A shape is a list. A plain string names a field to copy as it is. A
 * 'field' => [...] entry names a nested object and lists its fields. A
 * 'field' => ['*' => [...]] entry names a list of objects and lists the fields
 * of each one.
 *
 * Endpoints that read the override table (MyOverrideController::present) and
 * the two POST endpoints (OverrideController::store, AiFeedbackController::store)
 * build their arrays by hand from named columns. They never return a model.
 */
final class ApiFields
{
    /** GET /api/readings */
    public const READINGS = [
        'scenario', 'tick',
        'reading' => ['air_temp_k', 'process_temp_k', 'rotational_speed_rpm', 'torque_nm', 'tool_wear_min', 'vibration_mm_s', 'run_hours'],
        'risk' => [
            'score', 'band', 'band_label', 'action', 'explanation',
            'drivers' => ['*' => ['key', 'label', 'stress', 'scored']],
        ],
        'estimate' => ['probability', 'percent', 'range', 'range_records', 'range_failed'],
    ];

    /** GET /api/diagnosis */
    public const DIAGNOSIS = [
        'source', 'reason',
        'tokens' => ['input', 'output'],
        'data' => [
            'cause', 'checks', 'evidence',
            'tested_fix' => ['channel', 'value', 'score_from', 'score_to', 'text'],
        ],
        'trace' => ['*' => ['tool', 'summary', 'ok']],
    ];

    /** GET /api/work-order */
    public const WORK_ORDER = [
        'source', 'reason',
        'tokens' => ['input', 'output'],
        'data' => ['title', 'priority', 'steps'],
    ];

    /**
     * @param  array<mixed>  $data
     * @param  array<mixed>  $shape
     * @return array<mixed>
     */
    public static function pick(array $data, array $shape): array
    {
        $out = [];

        foreach ($shape as $key => $rule) {
            if (is_int($key)) {
                if (array_key_exists($rule, $data)) {
                    $out[$rule] = $data[$rule];
                }

                continue;
            }

            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (! is_array($value)) {
                $out[$key] = $value;
            } elseif (isset($rule['*'])) {
                $out[$key] = array_values(array_map(
                    fn ($item) => is_array($item) ? self::pick($item, $rule['*']) : $item,
                    $value,
                ));
            } else {
                $out[$key] = self::pick($value, $rule);
            }
        }

        return $out;
    }
}
