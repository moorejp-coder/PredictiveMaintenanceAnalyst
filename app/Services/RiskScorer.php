<?php

namespace App\Services;

/**
 * Failure-risk score for one milling machine, from sensor readings.
 *
 * The limits come from the AI4I 2020 dataset documentation (Matzka, 2020).
 * They were checked against all 10,000 labeled records: the heat, power and
 * overstrain rules reproduce every label. Random failures are not predictable
 * from any reading, so they are not scored.
 *
 * Vibration and machine run hours are not in AI4I, so those two checks use demo
 * limits and are not backtested. Vibration uses the ISO 10816-3 Group 2 zone
 * boundaries (1.4, 2.8 and 4.5 mm/s RMS). The 8,000 hour overhaul interval is a
 * placeholder. Both readings are optional: a reading without them scores as before.
 *
 * Each check gets a stress value from 0 to 1, where 1 means the limit is
 * crossed. The score is the highest stress, shown from 0 to 100.
 * A machine learning model can replace this class later without changing the page.
 */
final class RiskScorer
{
    /** Overstrain limit in minute-newton-meters, by product quality variant. */
    public const OVERSTRAIN_LIMIT = ['L' => 11000, 'M' => 12000, 'H' => 13000];

    public const POWER_MIN_W = 3500;

    public const POWER_MAX_W = 9000;

    public const HEAT_GAP_LIMIT = 8.6;

    public const HEAT_SPEED_LIMIT = 1380;

    public const TOOL_WINDOW_START = 200;

    public const TOOL_WINDOW_END = 240;

    /** Vibration in mm/s RMS: below WARN is normal, at LIMIT damage is likely. Demo values. */
    public const VIBRATION_WARN = 1.4;

    public const VIBRATION_LIMIT = 4.5;

    /** Machine hours since the last overhaul. Demo placeholder. */
    public const RUN_HOURS_START = 6000;

    public const RUN_HOURS_LIMIT = 8000;

    public const BAND_ELEVATED_FROM = 40;

    public const BAND_HIGH_FROM = 70;

    /** Tool wear alone never reads as certain failure: only about 5% of records in the 200-240 min window failed. */
    private const TOOL_WEAR_CAP = 0.6;

    /** Run hours alone never read as certain failure: a machine past its interval needs service, not a stop. */
    private const RUN_HOURS_CAP = 0.5;

    public function __construct(private readonly string $quality = 'L')
    {
    }

    /**
     * @param  array{air_temp_k: float, process_temp_k: float, rotational_speed_rpm: float, torque_nm: float, tool_wear_min: float, vibration_mm_s?: float, run_hours?: float}  $reading
     * @return array{score: int, band: string, band_label: string, action: string, explanation: string, drivers: list<array{key: string, label: string, stress: int}>, metrics: array<string, float>}
     */
    public function score(array $reading): array
    {
        $air = (float) $reading['air_temp_k'];
        $process = (float) $reading['process_temp_k'];
        $speed = (float) $reading['rotational_speed_rpm'];
        $torque = (float) $reading['torque_nm'];
        $wear = (float) $reading['tool_wear_min'];
        $vibration = isset($reading['vibration_mm_s']) ? (float) $reading['vibration_mm_s'] : null;
        $hours = isset($reading['run_hours']) ? (float) $reading['run_hours'] : null;

        $powerW = $torque * $speed * 2 * M_PI / 60;
        $gap = $process - $air;
        $strain = $wear * $torque;
        $limit = self::OVERSTRAIN_LIMIT[$this->quality];
        $strainRatio = $strain / $limit;

        $stress = [
            'tool_wear' => self::TOOL_WEAR_CAP * self::clamp(($wear - 150) / 50),
            'heat' => min(
                self::clamp((self::HEAT_GAP_LIMIT + 1 - $gap) / 1.0),
                self::clamp((self::HEAT_SPEED_LIMIT + 100 - $speed) / 100),
            ),
            'power' => max(
                self::clamp((self::POWER_MIN_W + 1000 - $powerW) / 1000),
                self::clamp(($powerW - (self::POWER_MAX_W - 1000)) / 1000),
            ),
            'overstrain' => self::clamp(($strainRatio - 0.8) / 0.2),
            'vibration' => $vibration === null ? 0.0
                : self::clamp(($vibration - self::VIBRATION_WARN) / (self::VIBRATION_LIMIT - self::VIBRATION_WARN)),
            'run_hours' => $hours === null ? 0.0
                : self::RUN_HOURS_CAP * self::clamp(($hours - self::RUN_HOURS_START) / (self::RUN_HOURS_LIMIT - self::RUN_HOURS_START)),
        ];

        $score = (int) round(100 * max($stress));
        $band = $this->band($score);

        $labels = [
            'tool_wear' => 'Tool wear',
            'heat' => 'Heat buildup',
            'power' => 'Power draw',
            'overstrain' => 'Overstrain',
            'vibration' => 'Vibration',
            'run_hours' => 'Machine hours',
        ];

        $drivers = [];
        foreach ($stress as $key => $value) {
            $drivers[] = [
                'key' => $key,
                'label' => $labels[$key],
                'stress' => (int) round(100 * $value),
            ];
        }

        $sentences = [
            'tool_wear' => sprintf(
                'The tool has run %d min. In the AI4I data, tool failures fall between %d and %d min. Plan a tool change.',
                $wear,
                self::TOOL_WINDOW_START,
                self::TOOL_WINDOW_END,
            ),
            'heat' => sprintf(
                'Heat is not leaving the machine. The process runs only %.1f degrees warmer than the air (limit: under %.1f) at %s rpm (limit: under %s).',
                $gap,
                self::HEAT_GAP_LIMIT,
                number_format($speed),
                number_format(self::HEAT_SPEED_LIMIT),
            ),
            'power' => $powerW >= (self::POWER_MIN_W + self::POWER_MAX_W) / 2
                ? sprintf(
                    'Power draw is high at %s W. The safe range in the data is %s to %s W.',
                    number_format($powerW),
                    number_format(self::POWER_MIN_W),
                    number_format(self::POWER_MAX_W),
                )
                : sprintf(
                    'Power draw is low at %s W. The safe range in the data is %s to %s W.',
                    number_format($powerW),
                    number_format(self::POWER_MIN_W),
                    number_format(self::POWER_MAX_W),
                ),
            'overstrain' => sprintf(
                'Tool wear (%d min) and torque (%.1f Nm) together reach %d%% of the overstrain limit. Past 100%%, every such record in the AI4I data failed.',
                $wear,
                $torque,
                round(100 * $strainRatio),
            ),
            'vibration' => sprintf(
                'Vibration is %.1f mm/s. Normal is under %.1f. Damage is likely above %.1f. Check the spindle, bearings and tool.',
                $vibration ?? 0.0,
                self::VIBRATION_WARN,
                self::VIBRATION_LIMIT,
            ),
            'run_hours' => sprintf(
                'The machine has run %s hours since its last overhaul. This demo assumes an overhaul every %s hours. Book the service.',
                number_format($hours ?? 0.0),
                number_format(self::RUN_HOURS_LIMIT),
            ),
        ];

        arsort($stress);
        $lines = [];
        foreach ($stress as $key => $value) {
            if ($value >= 0.4 && count($lines) < 2) {
                $lines[] = $sentences[$key];
            }
        }

        // A score of 20 or more with no reading past 40% still gets its top reason.
        if ($lines === [] && $score >= 20) {
            $lines[] = $sentences[array_key_first($stress)];
        }

        $explanation = $lines === []
            ? 'All readings sit inside their limits. Nothing points to a developing failure.'
            : implode(' ', $lines);

        return [
            'score' => $score,
            'band' => $band,
            'band_label' => ucfirst($band),
            'action' => match ($band) {
                'high' => 'Stop the machine and inspect it now.',
                'elevated' => 'Schedule an inspection at the next stop.',
                default => 'No action needed. Keep monitoring.',
            },
            'explanation' => $explanation,
            'drivers' => $drivers,
            'metrics' => [
                'power_w' => round($powerW, 0),
                'temp_gap_k' => round($gap, 1),
                'strain_percent' => round(100 * $strainRatio, 0),
                'vibration_mm_s' => $vibration === null ? 0.0 : round($vibration, 2),
            ],
        ];
    }

    public function band(int $score): string
    {
        return match (true) {
            $score >= self::BAND_HIGH_FROM => 'high',
            $score >= self::BAND_ELEVATED_FROM => 'elevated',
            default => 'low',
        };
    }

    private static function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
