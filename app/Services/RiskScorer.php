<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Checks\Check;
use App\Services\Checks\HeatCheck;
use App\Services\Checks\OverstrainCheck;
use App\Services\Checks\PowerCheck;
use App\Services\Checks\RunHoursCheck;
use App\Services\Checks\SpeedCheck;
use App\Services\Checks\ToolWearCheck;
use App\Services\Checks\VibrationCheck;

/**
 * Failure-risk score for one milling machine, from sensor readings.
 *
 * This class collects results. It holds no limit and writes no sentence.
 * Each failure check lives in App\Services\Checks and owns its stress value,
 * its sentence and its derived numbers. Every limit lives in config/prism.php
 * with its source.
 *
 * The AI4I limits were checked against all 10,000 labeled records: the heat,
 * power and overstrain rules reproduce every label. Random failures are not
 * predictable from any reading, so they are not scored. Vibration and machine
 * run hours are not in AI4I, so those two checks use demo limits and are not
 * backtested.
 *
 * Each check gets a stress value from 0 to 1, where 1 means the limit is
 * crossed. The score is the highest stress, shown from 0 to 100.
 * A machine learning model can replace this class later without changing the page.
 *
 * Rotational speed is reported as a seventh driver for context. It has no
 * limit of its own, so it is marked scored = false and never changes the
 * score, the explanation or the AI input.
 */
final class RiskScorer
{
    /** @var list<Check> */
    private readonly array $checks;

    /** @var array<string, array<string, mixed>> */
    private readonly array $limits;

    /** Context only. Not in the scored list. */
    private readonly SpeedCheck $speed;

    /** @param  array<string, array<string, mixed>>|null  $limits  Defaults to config/prism.php. */
    public function __construct(string $quality = 'L', ?array $limits = null)
    {
        $this->limits = $limits ?? Limits::all();

        // Order matters: on equal stress, the earlier check is listed first.
        $this->checks = [
            new ToolWearCheck($this->limits['tool_wear']),
            new HeatCheck($this->limits['heat']),
            new PowerCheck($this->limits['power']),
            new OverstrainCheck($this->limits['overstrain'], $quality),
            new VibrationCheck($this->limits['vibration']),
            new RunHoursCheck($this->limits['run_hours']),
        ];

        $this->speed = new SpeedCheck($this->limits['heat']);
    }

    /** @return list<Check> */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @param  array<string, float|int>  $reading  Needs air_temp_k, process_temp_k, rotational_speed_rpm, torque_nm and tool_wear_min. vibration_mm_s and run_hours are optional.
     * @return array{score: int, band: string, band_label: string, action: string, explanation: string, drivers: list<array{key: string, label: string, stress: int, scored: bool}>, metrics: array<string, float>}
     */
    public function score(array $reading): array
    {
        $stress = [];
        $byKey = [];
        $metrics = [];
        foreach ($this->checks as $check) {
            $stress[$check->key()] = $check->stress($reading);
            $byKey[$check->key()] = $check;
            $metrics += $check->metrics($reading);
        }

        $score = (int) round(100 * max($stress));
        $band = $this->band($score);

        $drivers = [];
        foreach ($stress as $key => $value) {
            $drivers[] = [
                'key' => $key,
                'label' => $byKey[$key]->label(),
                'stress' => (int) round(100 * $value),
                'scored' => true,
            ];
        }

        // Context only: how close speed is to the heat rule's speed limit.
        $drivers[] = [
            'key' => $this->speed->key(),
            'label' => $this->speed->label(),
            'stress' => (int) round(100 * $this->speed->stress($reading)),
            'scored' => false,
        ];

        arsort($stress);
        $lines = [];
        foreach ($stress as $key => $value) {
            if ($value >= 0.4 && count($lines) < 2) {
                $lines[] = $byKey[$key]->sentence($reading);
            }
        }

        // A score of 20 or more with no reading past 40% still gets its top reason.
        if ($lines === [] && $score >= 20) {
            $lines[] = $byKey[array_key_first($stress)]->sentence($reading);
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
                'power_w' => $metrics['power_w'],
                'temp_gap_k' => $metrics['temp_gap_k'],
                'strain_percent' => $metrics['strain_percent'],
                'vibration_mm_s' => $metrics['vibration_mm_s'],
            ],
        ];
    }

    public function band(int $score): string
    {
        return match (true) {
            $score >= $this->limits['bands']['high_from'] => 'high',
            $score >= $this->limits['bands']['elevated_from'] => 'elevated',
            default => 'low',
        };
    }
}
