<?php

namespace Tests\Feature;

use App\Services\Ai\ToolBox;
use App\Services\RiskScorer;
use App\Services\SensorSimulator;
use Tests\TestCase;

class ToolBoxTest extends TestCase
{
    private function box(string $scenario = 'worn_tool', int $tick = 50): ToolBox
    {
        return new ToolBox($scenario, $tick);
    }

    public function test_every_tool_has_a_definition_with_a_schema(): void
    {
        $names = array_column(ToolBox::definitions(), 'name');

        $this->assertSame(['get_check', 'get_trend', 'time_to_limit', 'what_if', 'get_backtest'], $names);
        foreach (ToolBox::definitions() as $tool) {
            $this->assertSame('object', $tool['input_schema']['type']);
            $this->assertNotEmpty($tool['description']);
        }
    }

    public function test_get_check_returns_the_check_stress_and_sentence(): void
    {
        $out = $this->box()->run('get_check', ['driver' => 'tool_wear']);

        $this->assertSame('Tool wear', $out['result']['driver']);
        $this->assertGreaterThan(0, $out['result']['stress']);
        $this->assertStringContainsString('The tool has run', $out['result']['sentence']);
    }

    public function test_get_trend_reports_a_rising_tool_wear_slope(): void
    {
        $out = $this->box()->run('get_trend', ['channel' => 'tool_wear_min', 'window' => 10]);

        $this->assertSame(10, $out['result']['readings']);
        $this->assertGreaterThan(0, $out['result']['slope_per_reading']);
        $this->assertGreaterThan($out['result']['first'], $out['result']['last']);
    }

    public function test_a_trend_never_crosses_the_cycle_restart(): void
    {
        // Tick 61 is the second reading of the second cycle, so only two readings exist.
        $out = $this->box('worn_tool', 61)->run('get_trend', ['channel' => 'tool_wear_min', 'window' => 10]);

        $this->assertArrayHasKey('note', $out['result']);
    }

    public function test_time_to_limit_estimates_minutes_for_a_rising_driver(): void
    {
        $out = $this->box('overload', 40)->run('time_to_limit', ['driver' => 'power']);

        $this->assertContains($out['result']['status'], ['rising', 'at_top']);
        if ($out['result']['status'] === 'rising') {
            $this->assertSame(
                $out['result']['readings_to_limit'] * ToolBox::MINUTES_PER_READING,
                $out['result']['minutes_to_limit'],
            );
        }
    }

    public function test_time_to_limit_says_not_rising_for_a_healthy_machine(): void
    {
        $out = $this->box('healthy', 40)->run('time_to_limit', ['driver' => 'heat']);

        $this->assertSame('not_rising', $out['result']['status']);
    }

    public function test_what_if_scores_a_copy_and_leaves_the_real_score_alone(): void
    {
        $reading = (new SensorSimulator)->reading('overheating', 58);
        $real = (new RiskScorer('L'))->score($reading)['score'];

        $out = $this->box('overheating', 58)->run('what_if', ['channel' => 'rotational_speed_rpm', 'value' => 1500]);

        $this->assertSame($real, $out['result']['current_score']);
        $this->assertLessThan($real, $out['result']['score_if_changed']);
        $this->assertSame('lowers_score', $out['result']['effect']);
        // The real reading is unchanged after the call.
        $this->assertSame($real, (new RiskScorer('L'))->score((new SensorSimulator)->reading('overheating', 58))['score']);
    }

    public function test_what_if_marks_a_change_toward_failure_as_not_a_fix(): void
    {
        // Heat fails at a low speed, so a lower speed does not lower the score.
        $out = $this->box('overheating', 58)->run('what_if', ['channel' => 'rotational_speed_rpm', 'value' => 700]);

        $this->assertContains($out['result']['effect'], ['no_change', 'raises_score']);
    }

    public function test_get_backtest_reads_the_config_numbers(): void
    {
        $out = $this->box()->run('get_backtest', ['band' => 'high']);

        $this->assertSame(291, $out['result']['failed']);
        $this->assertSame(645, $out['result']['total']);
        $this->assertSame(45.1, $out['result']['failure_rate_percent']);
    }

    public function test_bad_arguments_are_refused(): void
    {
        $bad = [
            ['get_check', ['driver' => 'nope']],
            ['get_trend', ['channel' => 'torque_nm', 'window' => 'ten']],
            ['what_if', ['channel' => 'torque_nm', 'value' => 9999]],
            ['what_if', ['channel' => 'torque_nm', 'value' => 'high']],
            ['get_backtest', ['band' => 'x']],
            ['delete_everything', []],
        ];

        foreach ($bad as [$name, $args]) {
            try {
                $this->box()->run($name, $args);
                $this->fail("{$name} accepted bad arguments");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
