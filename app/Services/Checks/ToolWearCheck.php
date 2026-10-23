<?php

declare(strict_types=1);

namespace App\Services\Checks;

final class ToolWearCheck extends Check
{
    /** @param  array<string, float|int>  $limits */
    public function __construct(private readonly array $limits) {}

    public function key(): string
    {
        return 'tool_wear';
    }

    public function label(): string
    {
        return 'Tool wear';
    }

    public function stress(array $reading): float
    {
        $wear = (float) $reading['tool_wear_min'];
        $start = $this->limits['ramp_start_min'];
        $width = $this->limits['window_start_min'] - $start;

        return $this->limits['cap'] * self::clamp(($wear - $start) / $width);
    }

    public function sentence(array $reading): string
    {
        return sprintf(
            'The tool has run %d min. In the AI4I data, tool failures fall between %d and %d min.',
            (float) $reading['tool_wear_min'],
            $this->limits['window_start_min'],
            $this->limits['window_end_min'],
        );
    }
}
