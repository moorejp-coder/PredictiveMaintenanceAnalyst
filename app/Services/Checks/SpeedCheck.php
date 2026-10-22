<?php

namespace App\Services\Checks;

/**
 * Rotational speed as context. This is NOT one of the six scored checks.
 *
 * Speed has no limit of its own in the data. It feeds the heat rule (heat
 * fails when speed is under 1,380 rpm and the process runs under 8.6 K above
 * the air) and the power rule. This check shows how close speed is to the
 * heat rule's speed limit so the dashboard can show why the speed tile is
 * flagged. RiskScorer reports it as a driver with scored = false and leaves
 * it out of the score, the explanation and the AI input.
 */
final class SpeedCheck extends Check
{
    /** @param  array<string, float|int>  $limits  The 'heat' limits. */
    public function __construct(private readonly array $limits) {}

    public function key(): string
    {
        return 'speed';
    }

    public function label(): string
    {
        return 'Rotational speed';
    }

    public function stress(array $reading): float
    {
        $l = $this->limits;

        return self::clamp(
            ($l['speed_limit_rpm'] + $l['speed_margin_rpm'] - (float) $reading['rotational_speed_rpm']) / $l['speed_margin_rpm'],
        );
    }

    public function sentence(array $reading): string
    {
        return sprintf(
            'Rotational speed is %s rpm. Heat stops leaving the machine below %s rpm when the process runs close to the air temperature.',
            number_format((float) $reading['rotational_speed_rpm']),
            number_format($this->limits['speed_limit_rpm']),
        );
    }
}
