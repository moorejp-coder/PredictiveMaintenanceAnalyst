<?php

namespace Tests\Unit;

use App\Services\RiskScorer;
use App\Services\SensorSimulator;
use PHPUnit\Framework\TestCase;

class SensorSimulatorTest extends TestCase
{
    public function test_same_scenario_and_tick_give_the_same_reading(): void
    {
        $simulator = new SensorSimulator;

        $this->assertSame($simulator->reading('worn_tool', 12), $simulator->reading('worn_tool', 12));
    }

    public function test_healthy_scenario_never_leaves_the_low_band(): void
    {
        $simulator = new SensorSimulator;
        $scorer = new RiskScorer('L');

        for ($tick = 0; $tick < SensorSimulator::CYCLE * 2; $tick++) {
            $this->assertSame('low', $scorer->score($simulator->reading('healthy', $tick))['band']);
        }
    }

    public function test_each_fault_scenario_ends_in_the_high_band(): void
    {
        $simulator = new SensorSimulator;
        $scorer = new RiskScorer('L');

        foreach (['worn_tool', 'overheating', 'overload'] as $scenario) {
            $result = $scorer->score($simulator->reading($scenario, SensorSimulator::CYCLE - 1));

            $this->assertSame('high', $result['band'], $scenario);
        }
    }

    public function test_reading_includes_vibration_and_run_hours(): void
    {
        $reading = (new SensorSimulator)->reading('healthy', 0);

        $this->assertGreaterThan(0, $reading['vibration_mm_s']);
        $this->assertSame(1200.0, $reading['run_hours']);
    }

    public function test_run_hours_grow_within_a_cycle(): void
    {
        $simulator = new SensorSimulator;

        $this->assertGreaterThan($simulator->reading('worn_tool', 0)['run_hours'], $simulator->reading('worn_tool', 10)['run_hours']);
    }

    public function test_unknown_scenario_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SensorSimulator)->reading('nope', 0);
    }
}
