<?php

declare(strict_types=1);

namespace App\Services\Checks;

final class HeatCheck extends Check
{
    /** @param  array<string, float|int>  $limits */
    public function __construct(private readonly array $limits) {}

    public function key(): string
    {
        return 'heat';
    }

    public function label(): string
    {
        return 'Heat buildup';
    }

    public function stress(array $reading): float
    {
        $gap = $this->gap($reading);
        $speed = (float) $reading['rotational_speed_rpm'];
        $l = $this->limits;

        return min(
            self::clamp(($l['gap_limit_k'] + $l['gap_margin_k'] - $gap) / $l['gap_margin_k']),
            self::clamp(($l['speed_limit_rpm'] + $l['speed_margin_rpm'] - $speed) / $l['speed_margin_rpm']),
        );
    }

    public function sentence(array $reading): string
    {
        return sprintf(
            'Heat is not leaving the machine. The process runs only %.1f degrees warmer than the air at %s rpm. In the AI4I data, heat failures happen when the gap is under %.1f degrees and the speed is under %s rpm.',
            $this->gap($reading),
            number_format((float) $reading['rotational_speed_rpm']),
            $this->limits['gap_limit_k'],
            number_format($this->limits['speed_limit_rpm']),
        );
    }

    public function metrics(array $reading): array
    {
        return ['temp_gap_k' => round($this->gap($reading), 1)];
    }

    /** @param  array<string, float|int>  $reading */
    private function gap(array $reading): float
    {
        return (float) $reading['process_temp_k'] - (float) $reading['air_temp_k'];
    }
}
