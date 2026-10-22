<?php

namespace App\Services\Ml;

use RuntimeException;

/**
 * Evaluates the exported gradient boosting model. No Python runs in production.
 * ml/train_model.py writes resources/ml/failure_model.json; this class reads it.
 */
final class FailureModel
{
    /** @var list<string> */
    public readonly array $features;

    private readonly float $baseline;

    /** @var list<array{f: list<int>, t: list<float>, l: list<int>, r: list<int>, v: list<float>}> */
    private readonly array $trees;

    public function __construct(?string $path = null)
    {
        $path ??= resource_path('ml/failure_model.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || ! isset($data['features'], $data['baseline'], $data['trees'])) {
            throw new RuntimeException('The failure model file is missing or unreadable.');
        }
        $this->features = $data['features'];
        $this->baseline = (float) $data['baseline'];
        $this->trees = $data['trees'];
    }

    /** @param  list<float|int>  $x  Feature values in the order of $this->features. */
    public function predict(array $x): float
    {
        $raw = $this->baseline;
        foreach ($this->trees as $tree) {
            $i = 0;
            while ($tree['f'][$i] !== -1) {
                $i = $x[$tree['f'][$i]] <= $tree['t'][$i] ? $tree['l'][$i] : $tree['r'][$i];
            }
            $raw += $tree['v'][$i];
        }

        return 1 / (1 + exp(-$raw));
    }
}
