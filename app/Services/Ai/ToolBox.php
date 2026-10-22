<?php

namespace App\Services\Ai;

use App\Services\Limits;
use App\Services\Ml\Forecaster;
use App\Services\Ml\LearnedEstimate;
use App\Services\RiskScorer;
use App\Services\SensorSimulator;

/**
 * The six read-only tools Claude calls in the Investigator.
 *
 * The server rebuilds the reading from the scenario and tick, so a tool never
 * takes data from the browser or from the model. Arguments are fixed choices
 * or numbers inside a fixed range. No tool writes anything, and no tool changes
 * the real score: what_if scores a copy of the reading.
 */
final class ToolBox
{
    /** Each reading stands for 30 minutes of run time (see SensorSimulator). */
    public const MINUTES_PER_READING = 30;

    /** Channel => [min, max] accepted by what_if and get_trend. */
    public const CHANNELS = [
        'air_temp_k' => [280.0, 330.0],
        'process_temp_k' => [280.0, 350.0],
        'rotational_speed_rpm' => [500.0, 3000.0],
        'torque_nm' => [0.0, 100.0],
        'tool_wear_min' => [0.0, 300.0],
        'vibration_mm_s' => [0.0, 20.0],
        'run_hours' => [0.0, 20000.0],
    ];

    /** Channel => [plain label, unit], for the sentences code writes. */
    public const CHANNEL_LABELS = [
        'air_temp_k' => ['Air temperature', 'K'],
        'process_temp_k' => ['Process temperature', 'K'],
        'rotational_speed_rpm' => ['Rotational speed', 'rpm'],
        'torque_nm' => ['Torque', 'Nm'],
        'tool_wear_min' => ['Tool wear', 'min'],
        'vibration_mm_s' => ['Vibration', 'mm/s'],
        'run_hours' => ['Machine run hours', 'h'],
    ];

    public const DRIVERS = ['tool_wear', 'heat', 'power', 'overstrain', 'vibration', 'run_hours'];

    /** @var array<string, float|int> */
    private array $reading;

    /** @var array<string, mixed> */
    private array $risk;

    public function __construct(
        private readonly string $scenario,
        private readonly int $tick,
        private readonly string $quality = 'L',
    ) {
        $this->reading = (new SensorSimulator)->reading($scenario, $tick);
        $this->risk = $this->scorer()->score($this->reading);
    }

    private function scorer(): RiskScorer
    {
        return new RiskScorer($this->quality);
    }

    /**
     * Tool definitions for the Messages API.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $driver = ['type' => 'string', 'enum' => self::DRIVERS, 'description' => 'One of the six scored checks.'];
        $channel = ['type' => 'string', 'enum' => array_keys(self::CHANNELS), 'description' => 'One sensor channel.'];

        return [
            [
                'name' => 'get_check',
                'description' => 'Read one check for the current reading: its stress from 0 to 100, its plain sentence and its derived numbers.',
                'input_schema' => ['type' => 'object', 'properties' => ['driver' => $driver], 'required' => ['driver'], 'additionalProperties' => false],
            ],
            [
                'name' => 'get_trend',
                'description' => 'Read how one sensor channel moved over the last readings of the current cycle. Returns the first value, the last value and the slope per reading.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'channel' => $channel,
                    'window' => ['type' => 'integer', 'minimum' => 3, 'maximum' => 30, 'description' => 'How many recent readings to include.'],
                ], 'required' => ['channel', 'window'], 'additionalProperties' => false],
            ],
            [
                'name' => 'time_to_limit',
                'description' => 'Straight-line estimate of how many readings, and how many minutes, until one check reaches its top stress, from its recent rise. Simulated data only.',
                'input_schema' => ['type' => 'object', 'properties' => ['driver' => $driver], 'required' => ['driver'], 'additionalProperties' => false],
            ],
            [
                'name' => 'what_if',
                'description' => 'Score a copy of the current reading with one channel set to a new value. The effect field says lowers_score, no_change or raises_score. Only a change that lowers the score is a tested fix. The real score does not change.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'channel' => $channel,
                    'value' => ['type' => 'number', 'description' => 'The value to try, in the same unit as the reading.'],
                ], 'required' => ['channel', 'value'], 'additionalProperties' => false],
            ],
            [
                'name' => 'get_learned_estimate',
                'description' => 'Read the trained model\'s chance of failure for the current reading, and how often readings in the same range failed in the AI4I records. It also holds a projection: the chance a few readings ahead if the recent trend continues, and the first reading ahead where the chance reaches 20 and 50 percent. It is a second opinion next to the rule score. It never changes the score.',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
            ],
            [
                'name' => 'get_backtest',
                'description' => 'How often readings in a risk band were real failures in the 10,000 AI4I 2020 records.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'band' => ['type' => 'string', 'enum' => ['high', 'elevated', 'low']],
                ], 'required' => ['band'], 'additionalProperties' => false],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{result: array<string, mixed>, summary: string}
     *
     * @throws \InvalidArgumentException when the tool name or an argument is not valid
     */
    public function run(string $name, array $input): array
    {
        return match ($name) {
            'get_check' => $this->getCheck($this->driver($input)),
            'get_trend' => $this->getTrend($this->channel($input), $this->window($input)),
            'time_to_limit' => $this->timeToLimit($this->driver($input)),
            'what_if' => $this->whatIf($this->channel($input), $input['value'] ?? null),
            'get_learned_estimate' => $this->getLearnedEstimate(),
            'get_backtest' => $this->getBacktest($input['band'] ?? null),
            default => throw new \InvalidArgumentException('Unknown tool.'),
        };
    }

    /** @param  array<string, mixed>  $input */
    private function driver(array $input): string
    {
        $driver = $input['driver'] ?? null;
        if (! is_string($driver) || ! in_array($driver, self::DRIVERS, true)) {
            throw new \InvalidArgumentException('driver must be one of: '.implode(', ', self::DRIVERS));
        }

        return $driver;
    }

    /** @param  array<string, mixed>  $input */
    private function channel(array $input): string
    {
        $channel = $input['channel'] ?? null;
        if (! is_string($channel) || ! isset(self::CHANNELS[$channel])) {
            throw new \InvalidArgumentException('channel must be one of: '.implode(', ', array_keys(self::CHANNELS)));
        }

        return $channel;
    }

    /** @param  array<string, mixed>  $input */
    private function window(array $input): int
    {
        $window = $input['window'] ?? null;
        if (! is_int($window) && ! (is_float($window) && floor($window) === $window)) {
            throw new \InvalidArgumentException('window must be a whole number from 3 to 30.');
        }

        return max(3, min(30, (int) $window));
    }

    /** @return array{result: array<string, mixed>, summary: string} */
    private function getCheck(string $driver): array
    {
        foreach ($this->scorer()->checks() as $check) {
            if ($check->key() !== $driver) {
                continue;
            }
            $stress = 0;
            foreach ($this->risk['drivers'] as $row) {
                if ($row['key'] === $driver) {
                    $stress = $row['stress'];
                }
            }

            return [
                'result' => [
                    'driver' => $check->label(),
                    'stress' => $stress,
                    'sentence' => $check->sentence($this->reading),
                    'metrics' => $check->metrics($this->reading),
                ],
                'summary' => sprintf('Read the %s check: stress %d out of 100.', $check->label(), $stress),
            ];
        }

        throw new \InvalidArgumentException('Unknown driver.');
    }

    /**
     * Readings from the current cycle only. The simulator restarts each cycle
     * as if the tool were changed, so a trend must not cross the restart.
     *
     * @return list<array<string, float|int>>
     */
    private function history(int $window): array
    {
        $cycleStart = $this->tick - ($this->tick % SensorSimulator::CYCLE);
        $from = max($cycleStart, $this->tick - $window + 1);
        $sim = new SensorSimulator;
        $rows = [];
        for ($t = $from; $t <= $this->tick; $t++) {
            $rows[] = $sim->reading($this->scenario, $t);
        }

        return $rows;
    }

    /**
     * Least-squares slope per step of a list of numbers.
     *
     * @param  list<float>  $values
     */
    private static function slope(array $values): float
    {
        $n = count($values);
        $meanX = ($n - 1) / 2;
        $meanY = array_sum($values) / $n;
        $num = 0.0;
        $den = 0.0;
        foreach ($values as $i => $y) {
            $num += ($i - $meanX) * ($y - $meanY);
            $den += ($i - $meanX) ** 2;
        }

        return $den == 0.0 ? 0.0 : $num / $den;
    }

    /** @return array{result: array<string, mixed>, summary: string} */
    private function getTrend(string $channel, int $window): array
    {
        $rows = $this->history($window);
        if (count($rows) < 3) {
            return [
                'result' => ['channel' => $channel, 'readings' => count($rows), 'note' => 'Fewer than 3 readings in this cycle, so no trend.'],
                'summary' => sprintf('Looked for a %s trend. Too few readings in this cycle.', $channel),
            ];
        }
        $values = array_map(fn ($r) => (float) $r[$channel], $rows);
        $slope = round(self::slope($values), 3);

        return [
            'result' => [
                'channel' => $channel,
                'readings' => count($values),
                'first' => round($values[0], 2),
                'last' => round(end($values), 2),
                'slope_per_reading' => $slope,
            ],
            'summary' => sprintf('Read the %s trend over %d readings: %s per reading.', $channel, count($values), ($slope >= 0 ? '+' : '').$slope),
        ];
    }

    /** @return array{result: array<string, mixed>, summary: string} */
    private function timeToLimit(string $driver): array
    {
        $rows = $this->history(10);
        if (count($rows) < 3) {
            return [
                'result' => ['driver' => $driver, 'status' => 'too_few_readings'],
                'summary' => sprintf('Tried to estimate time to limit for %s. Too few readings in this cycle.', $driver),
            ];
        }

        $scorer = $this->scorer();
        $series = [];
        foreach ($rows as $row) {
            foreach ($scorer->score($row)['drivers'] as $d) {
                if ($d['key'] === $driver) {
                    $series[] = (float) $d['stress'];
                }
            }
        }
        $current = (float) end($series);
        $slope = self::slope($series);
        $cap = Limits::all()[$driver]['cap'] ?? 1.0;
        $target = (int) round(100 * $cap);

        if ($current >= $target) {
            $result = ['driver' => $driver, 'status' => 'at_top', 'stress' => (int) $current, 'target_stress' => $target];
            $text = sprintf('Estimated time to limit for %s. It is already at its top stress.', $driver);
        } elseif ($slope < 0.5) {
            $result = ['driver' => $driver, 'status' => 'not_rising', 'stress' => (int) $current, 'rise_per_reading' => round($slope, 2)];
            $text = sprintf('Estimated time to limit for %s. Stress is not rising.', $driver);
        } else {
            $readings = (int) ceil(($target - $current) / $slope);
            $result = [
                'driver' => $driver,
                'status' => 'rising',
                'stress' => (int) $current,
                'target_stress' => $target,
                'rise_per_reading' => round($slope, 2),
                'readings_to_limit' => $readings,
                'minutes_to_limit' => $readings * self::MINUTES_PER_READING,
                'method' => 'straight-line estimate on simulated data',
            ];
            $text = sprintf('Estimated time to limit for %s: about %d readings, %d minutes (straight line).', $driver, $readings, $readings * self::MINUTES_PER_READING);
        }

        return ['result' => $result, 'summary' => $text];
    }

    /** @return array{result: array<string, mixed>, summary: string} */
    private function whatIf(string $channel, mixed $value): array
    {
        [$min, $max] = self::CHANNELS[$channel];
        if (! is_int($value) && ! is_float($value)) {
            throw new \InvalidArgumentException('value must be a number.');
        }
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("value for {$channel} must be from {$min} to {$max}.");
        }

        $copy = $this->reading;
        $copy[$channel] = (float) $value;
        $new = $this->scorer()->score($copy);

        $drivers = [];
        foreach ($new['drivers'] as $d) {
            if ($d['scored'] && $d['stress'] >= 40) {
                $drivers[] = ['driver' => $d['label'], 'stress' => $d['stress']];
            }
        }

        $chance = $this->chances($copy);

        $effect = match (true) {
            $new['score'] < $this->risk['score'] => 'lowers_score',
            $new['score'] > $this->risk['score'] => 'raises_score',
            default => 'no_change',
        };

        return [
            'result' => [
                'channel' => $channel,
                'tried_value' => $value,
                'current_score' => $this->risk['score'],
                'current_band' => $this->risk['band'],
                'score_if_changed' => $new['score'],
                'band_if_changed' => $new['band'],
                'effect' => $effect,
                'drivers_at_40_or_more' => $drivers,
            ] + ($chance === null ? [] : [
                'learned_chance_percent_now' => $chance['now'],
                'learned_chance_percent_if_changed' => $chance['if_changed'],
            ]),
            'summary' => sprintf('Tried %s = %s on a copy: score %d to %d (%s).', $channel, $value, $this->risk['score'], $new['score'], $new['band']),
        ];
    }

    /**
     * The five columns the learned model reads, as floats.
     *
     * @param  array<string, float|int>  $reading
     * @return array{air_temp_k: float, process_temp_k: float, rotational_speed_rpm: float, torque_nm: float, tool_wear_min: float}
     */
    private static function shaped(array $reading): array
    {
        return [
            'air_temp_k' => (float) $reading['air_temp_k'],
            'process_temp_k' => (float) $reading['process_temp_k'],
            'rotational_speed_rpm' => (float) $reading['rotational_speed_rpm'],
            'torque_nm' => (float) $reading['torque_nm'],
            'tool_wear_min' => (float) $reading['tool_wear_min'],
        ];
    }

    /**
     * The trained model's chance of failure, in percent, for the real reading
     * and for a changed copy. Null when the model file cannot be read, so the
     * what_if still works without it.
     *
     * @param  array<string, float|int>  $copy
     * @return array{now: float, if_changed: float}|null
     */
    private function chances(array $copy): ?array
    {
        try {
            $learned = new LearnedEstimate;

            return [
                'now' => round(100 * $learned->estimate(self::shaped($this->reading), $this->quality)['probability'], 1),
                'if_changed' => round(100 * $learned->estimate(self::shaped($copy), $this->quality)['probability'], 1),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The learned chance a few readings ahead if the recent trend continues,
     * from the current cycle only. Null near the end of a cycle or when the
     * cycle has fewer than 3 readings.
     *
     * @return array{method: string, readings_ahead: int, minutes_ahead: int, chance_percent_then: float, first_reading_at_20_percent: int|null, first_reading_at_50_percent: int|null, looked_ahead_readings: int}|null
     */
    private function projection(): ?array
    {
        $maxAhead = min(30, SensorSimulator::CYCLE - 1 - ($this->tick % SensorSimulator::CYCLE));
        $rows = $this->history(10);
        if ($maxAhead < 1 || count($rows) < 3) {
            return null;
        }

        try {
            $chances = (new Forecaster)->chances($rows, $maxAhead, $this->quality);
        } catch (\Throwable) {
            return null;
        }
        $ahead = min(10, $maxAhead);

        return [
            'method' => sprintf('Straight-line projection of the last %d readings, scored by the trained model. Simulated data.', count($rows)),
            'readings_ahead' => $ahead,
            'minutes_ahead' => $ahead * self::MINUTES_PER_READING,
            'chance_percent_then' => $chances[$ahead - 1],
            'first_reading_at_20_percent' => Forecaster::firstAtOrAbove($chances, 20),
            'first_reading_at_50_percent' => Forecaster::firstAtOrAbove($chances, 50),
            'looked_ahead_readings' => $maxAhead,
        ];
    }

    /** @return array{result: array<string, mixed>, summary: string} */
    private function getLearnedEstimate(): array
    {
        try {
            $e = (new LearnedEstimate)->estimate(self::shaped($this->reading), $this->quality);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('The learned estimate is not available.');
        }

        $result = [
            'chance_percent' => round(100 * $e['probability'], 1),
            'source' => 'Gradient boosting trained on the AI4I 2020 records. A second opinion. The rule score stays the score.',
        ];
        $summary = sprintf('Read the learned estimate: %s chance of failure.', $e['percent']);
        if ($e['range'] !== null) {
            $result['range_of_estimates'] = $e['range'];
            $result['records_in_range'] = $e['range_records'];
            $result['failed_in_range'] = $e['range_failed'];
            $summary = sprintf('Read the learned estimate: %s chance of failure. In the AI4I data, %d of %d readings with an estimate from %s failed.', $e['percent'], $e['range_failed'], $e['range_records'], $e['range']);
        }

        $projection = $this->projection();
        if ($projection !== null) {
            $result['projection'] = $projection;
            $reach = $projection['first_reading_at_20_percent'];
            $summary .= $reach === null
                ? sprintf(' Projected %d readings ahead on the recent trend: %s%%, below 20%%.', $projection['readings_ahead'], $projection['chance_percent_then'])
                : sprintf(' Projected on the recent trend (straight line): the chance reaches 20%% in about %d readings.', $reach);
        }

        return ['result' => $result, 'summary' => $summary];
    }

    /** @return array{result: array<string, mixed>, summary: string} */
    private function getBacktest(mixed $band): array
    {
        $table = config('prism.backtest');
        if (! is_string($band) || ! isset($table[$band])) {
            throw new \InvalidArgumentException('band must be high, elevated or low.');
        }
        $failed = $table[$band]['failed'];
        $total = $table[$band]['total'];

        return [
            'result' => [
                'band' => $band,
                'failed' => $failed,
                'total' => $total,
                'failure_rate_percent' => round(100 * $failed / $total, 1),
                'source' => 'AI4I 2020, 10,000 records',
            ],
            'summary' => sprintf('Read the backtest for the %s band: %d of %d records failed.', $band, $failed, $total),
        ];
    }
}
