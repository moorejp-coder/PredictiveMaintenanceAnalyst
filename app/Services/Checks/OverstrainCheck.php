<?php

declare(strict_types=1);

namespace App\Services\Checks;

final class OverstrainCheck extends Check
{
    /** @param  array<string, mixed>  $limits */
    public function __construct(private readonly array $limits, private readonly string $quality = 'L') {}

    public function key(): string
    {
        return 'overstrain';
    }

    public function label(): string
    {
        return 'Overstrain';
    }

    public function stress(array $reading): float
    {
        return self::clamp(
            ($this->ratio($reading) - $this->limits['ramp_start']) / $this->limits['ramp_width'],
        );
    }

    public function sentence(array $reading): string
    {
        return sprintf(
            'Tool wear (%d min) and torque (%.1f Nm) together reach %d%% of the overstrain limit. Past 100%%, every such record in the AI4I data failed.',
            (float) $reading['tool_wear_min'],
            (float) $reading['torque_nm'],
            round(100 * $this->ratio($reading)),
        );
    }

    public function metrics(array $reading): array
    {
        return ['strain_percent' => round(100 * $this->ratio($reading), 0)];
    }

    /** @param  array<string, float|int>  $reading */
    private function ratio(array $reading): float
    {
        $strain = (float) $reading['tool_wear_min'] * (float) $reading['torque_nm'];

        return $strain / $this->limits['limit_by_quality'][$this->quality];
    }
}
