<?php

namespace App\Services\Ml;

use App\Services\SensorSimulator;

/**
 * Checks the Forecaster against the simulator. For each start reading it
 * projects ahead from the last 10 readings only, then compares the first
 * reading where the projected chance reaches a threshold with the first
 * reading where the chance of the real simulated reading reaches it.
 *
 * The simulator scenarios climb in straight lines by construction, so a
 * straight-line projection suits them. The result describes the simulator.
 * It is not evidence for a real machine.
 *
 * @phpstan-type Bucket array{cases: int, both: int, median_error: float|null, within_3: int, missed: int, false_alarm: int, neither: int}
 * @phpstan-type Result array{starts: int, buckets: array<int, Bucket>, same_reading: array{projected: int, projected_same: int, real: int, real_same: int}}
 */
final class ForecastCheck
{
    public const THRESHOLDS = [20, 50];

    public const WINDOW = 10;

    /**
     * @param  list<string>  $scenarios
     * @return Result
     */
    public static function run(array $scenarios, int $step = 3, string $quality = 'L'): array
    {
        $sim = new SensorSimulator;
        $learned = new LearnedEstimate;
        $forecaster = new Forecaster($learned);

        $starts = 0;
        $same = ['projected' => 0, 'projected_same' => 0, 'real' => 0, 'real_same' => 0];
        /** @var array<int, array{cases: int, both: int, within_3: int, missed: int, false_alarm: int, neither: int}> $count */
        $count = [];
        /** @var array<int, list<int>> $errors */
        $errors = [];
        foreach (self::THRESHOLDS as $th) {
            $count[$th] = ['cases' => 0, 'both' => 0, 'within_3' => 0, 'missed' => 0, 'false_alarm' => 0, 'neither' => 0];
            $errors[$th] = [];
        }

        foreach ($scenarios as $scenario) {
            // Ticks 2 to 58 of the first cycle: at least 3 readings behind, at least 1 ahead.
            for ($tick = 2; $tick <= SensorSimulator::CYCLE - 2; $tick += $step) {
                $rows = [];
                for ($t = max(0, $tick - self::WINDOW + 1); $t <= $tick; $t++) {
                    $rows[] = $sim->reading($scenario, $t);
                }
                $maxAhead = min(30, SensorSimulator::CYCLE - 1 - $tick);
                $predicted = $forecaster->chances($rows, $maxAhead, $quality);

                $actual = [];
                for ($k = 1; $k <= $maxAhead; $k++) {
                    $actual[] = round(100 * $learned->estimate($sim->reading($scenario, $tick + $k), $quality)['probability'], 1);
                }
                $now = round(100 * $learned->estimate($sim->reading($scenario, $tick), $quality)['probability'], 1);
                $starts++;

                // Does the chance pass 20% and 50% at the same reading? Only start readings below both count.
                if ($now < min(self::THRESHOLDS)) {
                    $lo = min(self::THRESHOLDS);
                    $hi = max(self::THRESHOLDS);
                    $p = Forecaster::firstAtOrAbove($predicted, $lo);
                    $a = Forecaster::firstAtOrAbove($actual, $lo);
                    if ($p !== null) {
                        $same['projected']++;
                        $same['projected_same'] += $p === Forecaster::firstAtOrAbove($predicted, $hi) ? 1 : 0;
                    }
                    if ($a !== null) {
                        $same['real']++;
                        $same['real_same'] += $a === Forecaster::firstAtOrAbove($actual, $hi) ? 1 : 0;
                    }
                }

                foreach (self::THRESHOLDS as $th) {
                    if ($now >= $th) {
                        continue;
                    }
                    $count[$th]['cases']++;
                    $p = Forecaster::firstAtOrAbove($predicted, $th);
                    $a = Forecaster::firstAtOrAbove($actual, $th);
                    if ($p !== null && $a !== null) {
                        $count[$th]['both']++;
                        $errors[$th][] = abs($p - $a);
                        $count[$th]['within_3'] += abs($p - $a) <= 3 ? 1 : 0;
                    } elseif ($a !== null) {
                        $count[$th]['missed']++;
                    } elseif ($p !== null) {
                        $count[$th]['false_alarm']++;
                    } else {
                        $count[$th]['neither']++;
                    }
                }
            }
        }

        $out = [];
        foreach (self::THRESHOLDS as $th) {
            $c = $count[$th];
            $out[$th] = [
                'cases' => $c['cases'],
                'both' => $c['both'],
                'median_error' => self::median($errors[$th]),
                'within_3' => $c['within_3'],
                'missed' => $c['missed'],
                'false_alarm' => $c['false_alarm'],
                'neither' => $c['neither'],
            ];
        }

        return ['starts' => $starts, 'buckets' => $out, 'same_reading' => $same];
    }

    /**
     * @param  Result  $result
     * @param  list<string>  $scenarios
     */
    public static function markdown(array $result, array $scenarios, int $step, string $date): string
    {
        $lines = [
            '# Forecast check',
            '',
            "Run on {$date}. Scenarios: ".implode(', ', $scenarios).". One start reading every {$step} ticks of the first 60 tick cycle. Each start projects ahead from its last ".self::WINDOW.' readings only, then the projection is compared with the readings that follow.',
            '',
            'Reproduce with `php artisan prism:forecast-check`. The check is deterministic and makes no API call.',
            '',
            '| Chance threshold | Start readings below it | Projected and real crossing both found | Median error (readings) | Within 3 readings | Real crossing missed | Projected crossing, no real one | Neither |',
            '|---|---|---|---|---|---|---|---|',
        ];
        foreach ($result['buckets'] as $th => $b) {
            $median = $b['median_error'] === null ? 'n/a' : (string) $b['median_error'];
            $lines[] = "| {$th}% | {$b['cases']} | {$b['both']} | {$median} | {$b['within_3']} of {$b['both']} | {$b['missed']} | {$b['false_alarm']} | {$b['neither']} |";
        }
        $lines[] = '';
        $s = $result['same_reading'];
        $lines[] = "There were {$result['starts']} start readings in all. The rows above count the start readings that began below the threshold.";
        $lines[] = '';
        $lines[] = "The learned chance rises steeply near a limit. Of {$s['projected']} projected crossings of the lower threshold, {$s['projected_same']} reached the higher threshold at the same reading. Of {$s['real']} real crossings, {$s['real_same']} did. This is why the two rows can match.";
        $lines[] = '';
        $lines[] = 'Limits:';
        $lines[] = '- The simulator scenarios climb in straight lines, so a straight-line projection suits them. The result describes the simulator, not a real machine.';
        $lines[] = '- The trained model scores both sides, so this checks the projection of the inputs, not the model.';
        $lines[] = '- Start readings from the same scenario overlap and are not independent cases.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** @param  list<int>  $values */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
