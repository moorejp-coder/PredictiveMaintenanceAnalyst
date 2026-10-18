<?php

namespace Tests\Unit;

use App\Services\RiskScorer;
use PHPUnit\Framework\TestCase;

class RiskScorerTest extends TestCase
{
    private function reading(float $air, float $process, int $rpm, float $torque, int $wear): array
    {
        return [
            'air_temp_k' => $air,
            'process_temp_k' => $process,
            'rotational_speed_rpm' => $rpm,
            'torque_nm' => $torque,
            'tool_wear_min' => $wear,
        ];
    }

    public function test_healthy_row_from_ai4i_scores_low(): void
    {
        // AI4I UDI 2: no failure.
        $result = (new RiskScorer('L'))->score($this->reading(298.2, 308.7, 1408, 46.3, 3));

        $this->assertSame(0, $result['score']);
        $this->assertSame('low', $result['band']);
    }

    public function test_heat_failure_row_from_ai4i_scores_high(): void
    {
        // AI4I UDI 3761: heat dissipation failure.
        $result = (new RiskScorer('L'))->score($this->reading(302.3, 310.9, 1377, 46.8, 166));

        $this->assertSame(100, $result['score']);
        $this->assertSame('high', $result['band']);
        $this->assertStringContainsString('Heat is not leaving the machine', $result['explanation']);
    }

    public function test_power_failure_row_from_ai4i_scores_high(): void
    {
        // AI4I UDI 51: power failure (very low torque at high speed).
        $result = (new RiskScorer('L'))->score($this->reading(298.9, 309.1, 2861, 4.6, 143));

        $this->assertSame('high', $result['band']);
        $this->assertStringContainsString('Power draw', $result['explanation']);
    }

    public function test_overstrain_failure_row_from_ai4i_scores_high(): void
    {
        // AI4I UDI 161: overstrain failure.
        $result = (new RiskScorer('L'))->score($this->reading(298.4, 308.2, 1282, 60.7, 216));

        $this->assertSame('high', $result['band']);
        $this->assertStringContainsString('overstrain limit', $result['explanation']);
    }

    public function test_tool_wear_alone_reads_elevated_not_high(): void
    {
        $result = (new RiskScorer('L'))->score($this->reading(300.0, 310.0, 1540, 30.0, 230));

        $this->assertSame(60, $result['score']);
        $this->assertSame('elevated', $result['band']);
    }

    public function test_high_vibration_alone_scores_high(): void
    {
        $result = (new RiskScorer('L'))->score($this->reading(300.0, 310.0, 1540, 40.0, 20) + ['vibration_mm_s' => 4.6, 'run_hours' => 1000.0]);

        $this->assertSame(100, $result['score']);
        $this->assertSame('high', $result['band']);
        $this->assertStringContainsString('Vibration is 4.6 mm/s', $result['explanation']);
    }

    public function test_vibration_in_the_iso_middle_zone_reads_elevated(): void
    {
        $result = (new RiskScorer('L'))->score($this->reading(300.0, 310.0, 1540, 40.0, 20) + ['vibration_mm_s' => 2.8, 'run_hours' => 1000.0]);

        $this->assertSame(45, $result['score']);
        $this->assertSame('elevated', $result['band']);
    }

    public function test_run_hours_alone_never_read_as_high(): void
    {
        $result = (new RiskScorer('L'))->score($this->reading(300.0, 310.0, 1540, 40.0, 20) + ['vibration_mm_s' => 1.0, 'run_hours' => 20000.0]);

        $this->assertSame(50, $result['score']);
        $this->assertSame('elevated', $result['band']);
        $this->assertStringContainsString('hours since its last overhaul', $result['explanation']);
    }

    public function test_reading_without_vibration_or_run_hours_still_scores(): void
    {
        $result = (new RiskScorer('L'))->score($this->reading(300.0, 310.0, 1540, 40.0, 20));

        $this->assertSame(0, $result['score']);
    }

    public function test_score_is_always_between_0_and_100(): void
    {
        $result = (new RiskScorer('L'))->score($this->reading(300.0, 310.0, 3000, 90.0, 300));

        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }
}
