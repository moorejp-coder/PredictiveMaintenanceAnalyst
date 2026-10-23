<?php

declare(strict_types=1);

namespace App\Services\Checks;

/** Run hours are optional. A reading without them scores 0 here. */
final class RunHoursCheck extends Check
{
    /** @param  array<string, float|int>  $limits */
    public function __construct(private readonly array $limits) {}

    public function key(): string
    {
        return 'run_hours';
    }

    public function label(): string
    {
        return 'Machine hours';
    }

    public function stress(array $reading): float
    {
        if (! isset($reading['run_hours'])) {
            return 0.0;
        }

        $start = $this->limits['start'];

        return $this->limits['cap']
            * self::clamp(((float) $reading['run_hours'] - $start) / ($this->limits['limit'] - $start));
    }

    public function sentence(array $reading): string
    {
        return sprintf(
            'The machine has run %s hours since its last overhaul. This demo assumes an overhaul every %s hours. Book the service.',
            number_format(isset($reading['run_hours']) ? (float) $reading['run_hours'] : 0.0),
            number_format($this->limits['limit']),
        );
    }
}
