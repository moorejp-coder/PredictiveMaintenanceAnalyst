<?php

declare(strict_types=1);

namespace App\Services\Ml;

use App\Services\Ai\ToolBox;

/**
 * Projects the five model inputs forward in a straight line from the most
 * recent readings, then scores each projected reading with the trained
 * failure model. The result is the learned chance of failure a few readings
 * from now if the recent trend continues.
 *
 * It is a straight-line extrapolation of the inputs, not a time series model.
 * It sees only the readings it is given, never the readings after them.
 */
final class Forecaster
{
    public function __construct(private readonly LearnedEstimate $learned = new LearnedEstimate) {}

    /**
     * Chance of failure in percent, one value per reading ahead (1 to $maxAhead).
     * Empty when fewer than 3 readings are given.
     *
     * @param  list<array<string, float|int>>  $rows  Recent readings, oldest first.
     * @return list<float>
     */
    public function chances(array $rows, int $maxAhead, string $quality = 'L'): array
    {
        $n = count($rows);
        if ($n < 3 || $maxAhead < 1) {
            return [];
        }

        $last = $rows[$n - 1];
        $slope = [];
        foreach (['air_temp_k', 'process_temp_k', 'rotational_speed_rpm', 'torque_nm', 'tool_wear_min'] as $channel) {
            $slope[$channel] = self::slope(array_map(fn (array $r): float => (float) $r[$channel], $rows));
        }

        $out = [];
        for ($k = 1; $k <= $maxAhead; $k++) {
            $future = [
                'air_temp_k' => self::at($last, $slope, 'air_temp_k', $k),
                'process_temp_k' => self::at($last, $slope, 'process_temp_k', $k),
                'rotational_speed_rpm' => self::at($last, $slope, 'rotational_speed_rpm', $k),
                'torque_nm' => self::at($last, $slope, 'torque_nm', $k),
                'tool_wear_min' => self::at($last, $slope, 'tool_wear_min', $k),
            ];
            $out[] = round(100 * $this->learned->estimate($future, $quality)['probability'], 1);
        }

        return $out;
    }

    /**
     * The first reading ahead (1 is the next reading) whose chance reaches the
     * percent, or null when none does.
     *
     * @param  list<float>  $chances
     */
    public static function firstAtOrAbove(array $chances, float $percent): ?int
    {
        foreach ($chances as $i => $c) {
            if ($c >= $percent) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * The last value plus the slope times the readings ahead, kept inside the
     * range the tools accept for the channel.
     *
     * @param  array<string, float|int>  $last
     * @param  array<string, float>  $slope
     */
    private static function at(array $last, array $slope, string $channel, int $k): float
    {
        [$min, $max] = ToolBox::CHANNELS[$channel];

        return max($min, min($max, (float) $last[$channel] + $slope[$channel] * $k));
    }

    /**
     * Least-squares slope per reading.
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
}
