<?php

namespace App\Services\Checks;

final class PowerCheck extends Check
{
    /** @param  array<string, float|int>  $limits */
    public function __construct(private readonly array $limits)
    {
    }

    public function key(): string
    {
        return 'power';
    }

    public function label(): string
    {
        return 'Power draw';
    }

    public function stress(array $reading): float
    {
        $power = $this->powerW($reading);
        $l = $this->limits;

        return max(
            self::clamp(($l['min_w'] + $l['margin_w'] - $power) / $l['margin_w']),
            self::clamp(($power - ($l['max_w'] - $l['margin_w'])) / $l['margin_w']),
        );
    }

    public function sentence(array $reading): string
    {
        $power = $this->powerW($reading);
        $l = $this->limits;

        return sprintf(
            'Power draw is %s at %s W. The safe range in the data is %s to %s W.',
            $power >= ($l['min_w'] + $l['max_w']) / 2 ? 'high' : 'low',
            number_format($power),
            number_format($l['min_w']),
            number_format($l['max_w']),
        );
    }

    public function metrics(array $reading): array
    {
        return ['power_w' => round($this->powerW($reading), 0)];
    }

    private function powerW(array $reading): float
    {
        return (float) $reading['torque_nm'] * (float) $reading['rotational_speed_rpm'] * 2 * M_PI / 60;
    }
}
