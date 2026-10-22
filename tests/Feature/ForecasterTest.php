<?php

namespace Tests\Feature;

use App\Services\Ai\ToolBox;
use App\Services\Ml\ForecastCheck;
use App\Services\Ml\Forecaster;
use App\Services\SensorSimulator;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ForecasterTest extends TestCase
{
    /** @return list<array<string, float|int>> */
    private function rows(string $scenario, int $tick, int $window = 10): array
    {
        $sim = new SensorSimulator;
        $rows = [];
        for ($t = max(0, $tick - $window + 1); $t <= $tick; $t++) {
            $rows[] = $sim->reading($scenario, $t);
        }

        return $rows;
    }

    public function test_it_returns_one_chance_per_reading_ahead_between_0_and_100(): void
    {
        $chances = (new Forecaster)->chances($this->rows('worn_tool', 30), 12);

        $this->assertCount(12, $chances);
        foreach ($chances as $c) {
            $this->assertGreaterThanOrEqual(0.0, $c);
            $this->assertLessThanOrEqual(100.0, $c);
        }
    }

    public function test_a_worn_tool_projection_ends_higher_than_it_starts(): void
    {
        $chances = (new Forecaster)->chances($this->rows('worn_tool', 30), 25);

        $this->assertGreaterThan($chances[0], $chances[24]);
    }

    public function test_a_healthy_machine_stays_low(): void
    {
        $chances = (new Forecaster)->chances($this->rows('healthy', 20), 20);

        $this->assertLessThan(20.0, max($chances));
    }

    public function test_it_needs_three_readings_and_one_reading_ahead(): void
    {
        $forecaster = new Forecaster;

        $this->assertSame([], $forecaster->chances($this->rows('worn_tool', 1), 5));
        $this->assertSame([], $forecaster->chances($this->rows('worn_tool', 30), 0));
    }

    public function test_it_sees_only_the_readings_it_is_given(): void
    {
        // The same last 10 readings give the same projection wherever they came from.
        $a = (new Forecaster)->chances($this->rows('overload', 25), 8);
        $b = (new Forecaster)->chances($this->rows('overload', 25), 8);

        $this->assertSame($a, $b);
    }

    public function test_first_at_or_above_counts_readings_from_one(): void
    {
        $this->assertSame(3, Forecaster::firstAtOrAbove([1.0, 5.0, 20.0, 60.0], 20));
        $this->assertSame(4, Forecaster::firstAtOrAbove([1.0, 5.0, 20.0, 60.0], 50));
        $this->assertNull(Forecaster::firstAtOrAbove([1.0, 5.0], 20));
    }

    public function test_the_learned_estimate_tool_carries_the_projection(): void
    {
        $out = (new ToolBox('worn_tool', 30))->run('get_learned_estimate', []);

        $p = $out['result']['projection'];
        $this->assertSame(10, $p['readings_ahead']);
        $this->assertSame(300, $p['minutes_ahead']);
        $this->assertStringContainsString('Straight-line projection', $p['method']);
        $this->assertStringContainsString('Simulated data', $p['method']);
        $this->assertArrayHasKey('first_reading_at_20_percent', $p);
        $this->assertArrayHasKey('first_reading_at_50_percent', $p);
    }

    public function test_there_is_no_projection_at_the_end_of_a_cycle_or_with_too_few_readings(): void
    {
        $end = (new ToolBox('worn_tool', 59))->run('get_learned_estimate', []);
        $start = (new ToolBox('worn_tool', 61))->run('get_learned_estimate', []);

        $this->assertArrayNotHasKey('projection', $end['result']);
        $this->assertArrayNotHasKey('projection', $start['result']);
        $this->assertArrayHasKey('chance_percent', $end['result']);
    }

    public function test_the_check_buckets_add_up(): void
    {
        $result = ForecastCheck::run(['worn_tool', 'overload'], 3);

        $this->assertSame(38, $result['starts']);
        foreach (ForecastCheck::THRESHOLDS as $th) {
            $b = $result['buckets'][$th];
            $this->assertSame($b['cases'], $b['both'] + $b['missed'] + $b['false_alarm'] + $b['neither']);
            $this->assertLessThanOrEqual($b['both'], $b['within_3']);
        }
    }

    public function test_the_check_command_prints_a_report_and_rejects_a_bad_scenario(): void
    {
        $this->assertSame(0, Artisan::call('prism:forecast-check', ['--scenario' => ['worn_tool'], '--every' => 6]));
        $output = Artisan::output();
        $this->assertStringContainsString('# Forecast check', $output);
        $this->assertStringContainsString('The result describes the simulator, not a real machine.', $output);
        $this->artisan('prism:forecast-check', ['--scenario' => ['nope']])->assertFailed();
    }
}
