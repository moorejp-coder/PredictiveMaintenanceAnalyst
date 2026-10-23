<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Simulated sensor readings for one milling machine.
 *
 * Stateless and repeatable: the same scenario and tick always give the same
 * reading, so the app needs no database. Baselines for the five AI4I columns
 * come from the AI4I 2020 dataset (air 300.0 K, process 10.0 K above air,
 * 1,539 rpm, 40.0 Nm). AI4I has no vibration or machine run hours, so those two
 * channels are invented for the demo. Noise sizes are chosen for the demo. Each scenario ramps over one cycle,
 * then restarts as if the tool were changed.
 */
final class SensorSimulator
{
    public const CYCLE = 60;

    public const SCENARIOS = [
        'worn_tool' => [
            'label' => 'Worn tool',
            'blurb' => 'Tool wear climbs while torque rises with it.',
        ],
        'overheating' => [
            'label' => 'Overheating',
            'blurb' => 'Heat stops leaving the machine as speed drops.',
        ],
        'overload' => [
            'label' => 'Overload',
            'blurb' => 'Torque climbs until power draw passes the safe range.',
        ],
        'healthy' => [
            'label' => 'Healthy',
            'blurb' => 'A normal shift. Every reading stays in range.',
        ],
    ];

    /**
     * @return array{air_temp_k: float, process_temp_k: float, rotational_speed_rpm: int, torque_nm: float, tool_wear_min: int, vibration_mm_s: float, run_hours: float}
     */
    public function reading(string $scenario, int $tick): array
    {
        if (! isset(self::SCENARIOS[$scenario])) {
            throw new \InvalidArgumentException("Unknown scenario: {$scenario}");
        }

        $tick = max(0, $tick);
        $f = ($tick % self::CYCLE) / (self::CYCLE - 1);

        [$air, $gap, $speed, $torque, $wear] = match ($scenario) {
            'worn_tool' => [300.0, 10.0, 1500.0, 44.0 + 12.0 * $f, 150.0 + 95.0 * $f],
            'overheating' => [300.0 + 3.0 * $f, 10.2 - 2.6 * $f, 1520.0 - 230.0 * $f, 40.0, 70.0 + 20.0 * $f],
            'overload' => [300.0, 10.0, 1500.0 - 40.0 * $f, 44.0 + 28.0 * $f, 90.0 + 30.0 * $f],
            default => [300.0, 10.0, 1540.0, 40.0, 25.0 + 90.0 * $f],
        };

        // Vibration (mm/s RMS) and machine run hours since the last overhaul.
        // Each reading stands for 30 minutes of run time.
        [$vibration, $baseHours] = match ($scenario) {
            'worn_tool' => [1.2 + 2.6 * $f, 7400.0],
            'overheating' => [1.0 + 1.4 * $f, 3100.0],
            'overload' => [1.3 + 3.4 * $f, 4800.0],
            default => [1.0, 1200.0],
        };
        $vibration += 0.08 * $this->gaussian($scenario, $tick, 5);
        $hours = $baseHours + 0.5 * ($tick % self::CYCLE);

        $air += 0.2 * $this->gaussian($scenario, $tick, 1);
        $gap += 0.35 * $this->gaussian($scenario, $tick, 2);
        $speed += 14.0 * $this->gaussian($scenario, $tick, 3);
        $torque += 1.0 * $this->gaussian($scenario, $tick, 4);

        return [
            'air_temp_k' => round($air, 1),
            'process_temp_k' => round($air + $gap, 1),
            'rotational_speed_rpm' => (int) round($speed),
            'torque_nm' => round(max(0.0, $torque), 1),
            'tool_wear_min' => (int) round($wear),
            'vibration_mm_s' => round(max(0.1, $vibration), 2),
            'run_hours' => round($hours, 1),
        ];
    }

    /** Standard normal value from hashed uniforms (Box-Muller). No global random state. */
    private function gaussian(string $scenario, int $tick, int $channel): float
    {
        $u1 = max($this->uniform("{$scenario}:{$tick}:{$channel}:a"), 1e-9);
        $u2 = $this->uniform("{$scenario}:{$tick}:{$channel}:b");

        return max(-3.0, min(3.0, sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2)));
    }

    private function uniform(string $key): float
    {
        return hexdec(substr(md5($key), 0, 8)) / 0xFFFFFFFF;
    }
}
