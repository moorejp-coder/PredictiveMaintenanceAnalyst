<?php

namespace Tests\Unit;

use App\Services\Checks\HeatCheck;
use App\Services\Checks\OverstrainCheck;
use App\Services\Checks\PowerCheck;
use App\Services\Checks\RunHoursCheck;
use App\Services\Checks\SpeedCheck;
use App\Services\Checks\ToolWearCheck;
use App\Services\Checks\VibrationCheck;
use App\Services\Limits;
use App\Services\RiskScorer;
use PHPUnit\Framework\TestCase;

class ChecksTest extends TestCase
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

    private function healthy(): array
    {
        return $this->reading(300.0, 310.0, 1540, 40.0, 20);
    }

    public function test_scorer_runs_the_six_checks_in_a_fixed_order(): void
    {
        $keys = array_map(fn ($check) => $check->key(), (new RiskScorer('L'))->checks());

        $this->assertSame(['tool_wear', 'heat', 'power', 'overstrain', 'vibration', 'run_hours'], $keys);
    }

    public function test_speed_check_reaches_full_stress_at_the_heat_speed_limit(): void
    {
        $check = new SpeedCheck(Limits::all()['heat']);

        $this->assertEqualsWithDelta(1.0, $check->stress($this->reading(300.0, 310.0, 1380, 40.0, 20)), 1e-9);
        $this->assertEqualsWithDelta(0.5, $check->stress($this->reading(300.0, 310.0, 1430, 40.0, 20)), 1e-9);
        $this->assertEqualsWithDelta(0.0, $check->stress($this->reading(300.0, 310.0, 1540, 40.0, 20)), 1e-9);
    }

    public function test_limits_load_from_the_config_file_without_booting_laravel(): void
    {
        $limits = Limits::all();

        $this->assertSame(70, $limits['bands']['high_from']);
        $this->assertSame(11000, $limits['overstrain']['limit_by_quality']['L']);
        $this->assertSame(1380, $limits['heat']['speed_limit_rpm']);
    }

    public function test_tool_wear_check_is_capped_at_its_limit(): void
    {
        $check = new ToolWearCheck(Limits::all()['tool_wear']);

        $this->assertEqualsWithDelta(0.0, $check->stress($this->reading(300.0, 310.0, 1540, 30.0, 150)), 1e-9);
        $this->assertEqualsWithDelta(0.6, $check->stress($this->reading(300.0, 310.0, 1540, 30.0, 230)), 1e-9);
    }

    public function test_heat_check_flags_ai4i_heat_failure_row(): void
    {
        // AI4I UDI 3761: heat dissipation failure.
        $check = new HeatCheck(Limits::all()['heat']);
        $row = $this->reading(302.3, 310.9, 1377, 46.8, 166);

        $this->assertEqualsWithDelta(1.0, $check->stress($row), 1e-6);
        $this->assertSame(8.6, $check->metrics($row)['temp_gap_k']);
        $this->assertEqualsWithDelta(0.0, $check->stress($this->healthy()), 1e-9);
    }

    public function test_power_check_flags_low_power_and_names_it(): void
    {
        // AI4I UDI 51: power failure (very low torque at high speed).
        $check = new PowerCheck(Limits::all()['power']);
        $row = $this->reading(298.9, 309.1, 2861, 4.6, 143);

        $this->assertEqualsWithDelta(1.0, $check->stress($row), 1e-9);
        $this->assertStringContainsString('Power draw is low', $check->sentence($row));
    }

    public function test_overstrain_check_uses_the_limit_for_the_quality_variant(): void
    {
        $limits = Limits::all()['overstrain'];
        $row = $this->reading(300.0, 310.0, 1500, 50.0, 220); // strain 11,000

        $this->assertEqualsWithDelta(1.0, (new OverstrainCheck($limits, 'L'))->stress($row), 1e-9);
        $this->assertLessThan(1.0, (new OverstrainCheck($limits, 'H'))->stress($row));
        $this->assertSame(100.0, (new OverstrainCheck($limits, 'L'))->metrics($row)['strain_percent']);
    }

    public function test_vibration_check_scores_zero_without_a_reading(): void
    {
        $check = new VibrationCheck(Limits::all()['vibration']);

        $this->assertSame(0.0, $check->stress($this->healthy()));
        $this->assertSame(0.0, $check->metrics($this->healthy())['vibration_mm_s']);
        $this->assertEqualsWithDelta(1.0, $check->stress($this->healthy() + ['vibration_mm_s' => 4.5]), 1e-9);
    }

    public function test_run_hours_check_is_capped_at_half(): void
    {
        $check = new RunHoursCheck(Limits::all()['run_hours']);

        $this->assertSame(0.0, $check->stress($this->healthy()));
        $this->assertEqualsWithDelta(0.5, $check->stress($this->healthy() + ['run_hours' => 20000.0]), 1e-9);
    }

    public function test_scorer_accepts_custom_limits(): void
    {
        $limits = Limits::all();
        $limits['bands']['high_from'] = 90;
        $limits['bands']['elevated_from'] = 50;

        $scorer = new RiskScorer('L', $limits);

        $this->assertSame('elevated', $scorer->band(80));
        $this->assertSame('high', $scorer->band(90));
        $this->assertSame('low', $scorer->band(49));
    }
}
