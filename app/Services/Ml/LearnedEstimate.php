<?php

namespace App\Services\Ml;

use App\Services\RiskScorer;

/**
 * A second opinion next to the rule score. It never changes the score, the band
 * or the action. It reports a chance of failure and how often readings in the
 * same range failed in the AI4I data, using predictions made on rows the model
 * had not seen (see resources/ml/model_card.json).
 */
final class LearnedEstimate
{
    private ?FailureModel $model = null;

    /** @var array<string, mixed>|null */
    private ?array $card = null;

    public function __construct(private readonly ?string $modelPath = null, private readonly ?string $cardPath = null) {}

    /**
     * @param  array{air_temp_k: float, process_temp_k: float, rotational_speed_rpm: float, torque_nm: float, tool_wear_min: float}  $reading
     * @return array{probability: float, percent: string, range: string|null, range_records: int|null, range_failed: int|null}
     */
    public function estimate(array $reading, string $quality = 'L'): array
    {
        $p = $this->model()->predict($this->vector($reading, $quality));
        $range = $this->rangeFor($p);

        return [
            'probability' => $p,
            'percent' => self::format($p),
            'range' => $range === null ? null : self::rangeLabel($range['from'], $range['to']),
            'range_records' => $range['records'] ?? null,
            'range_failed' => $range['failed'] ?? null,
        ];
    }

    /**
     * Same order as FEATURES in ml/train_model.py.
     *
     * @param  array<string, float|int>  $reading
     * @return list<float|int>
     */
    public function vector(array $reading, string $quality = 'L'): array
    {
        $air = (float) $reading['air_temp_k'];
        $proc = (float) $reading['process_temp_k'];
        $rpm = (float) $reading['rotational_speed_rpm'];
        $torque = (float) $reading['torque_nm'];
        $wear = (float) $reading['tool_wear_min'];

        $stress = [];
        foreach ((new RiskScorer($quality))->score($reading)['drivers'] as $d) {
            $stress[$d['key']] = $d['stress'];
        }

        return [
            $air, $proc, $rpm, $torque, $wear,
            $quality === 'M' ? 1 : 0, $quality === 'H' ? 1 : 0,
            $proc - $air,
            $torque * $rpm * 2 * M_PI / 60,
            $wear * $torque,
            $stress['tool_wear'] ?? 0, $stress['heat'] ?? 0, $stress['power'] ?? 0, $stress['overstrain'] ?? 0,
        ];
    }

    public static function format(float $p): string
    {
        if ($p < 0.001) {
            return 'under 0.1%';
        }
        if ($p < 0.01) {
            return number_format($p * 100, 1).'%';
        }

        return number_format($p * 100, 0).'%';
    }

    /** @return array{from: float, to: float, records: int, failed: int, rate: float|null}|null */
    private function rangeFor(float $p): ?array
    {
        foreach ($this->card()['calibration'] ?? [] as $bin) {
            if ($p >= $bin['from'] && ($p < $bin['to'] || $bin['to'] >= 1.0)) {
                return $bin;
            }
        }

        return null;
    }

    private static function rangeLabel(float $from, float $to): string
    {
        return round($from * 100).'% to '.round($to * 100).'%';
    }

    private function model(): FailureModel
    {
        return $this->model ??= new FailureModel($this->modelPath);
    }

    /** @return array<string, mixed> */
    private function card(): array
    {
        if ($this->card === null) {
            $path = $this->cardPath ?? resource_path('ml/model_card.json');
            $this->card = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        }

        return $this->card;
    }
}
