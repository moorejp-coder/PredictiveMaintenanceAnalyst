<?php

declare(strict_types=1);

namespace App\Services\Checks;

/** Vibration is optional. A reading without it scores 0 here. */
final class VibrationCheck extends Check
{
    /** @param  array<string, float|int>  $limits */
    public function __construct(private readonly array $limits) {}

    public function key(): string
    {
        return 'vibration';
    }

    public function label(): string
    {
        return 'Vibration';
    }

    public function stress(array $reading): float
    {
        if (! isset($reading['vibration_mm_s'])) {
            return 0.0;
        }

        $warn = $this->limits['warn'];

        return self::clamp(((float) $reading['vibration_mm_s'] - $warn) / ($this->limits['limit'] - $warn));
    }

    public function sentence(array $reading): string
    {
        return sprintf(
            'Vibration is %.1f mm/s. Normal is under %.1f. Damage is likely above %.1f. Check the spindle, bearings and tool.',
            isset($reading['vibration_mm_s']) ? (float) $reading['vibration_mm_s'] : 0.0,
            $this->limits['warn'],
            $this->limits['limit'],
        );
    }

    public function metrics(array $reading): array
    {
        return [
            'vibration_mm_s' => isset($reading['vibration_mm_s']) ? round((float) $reading['vibration_mm_s'], 2) : 0.0,
        ];
    }
}
