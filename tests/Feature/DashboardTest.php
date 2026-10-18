<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_dashboard_page_loads(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Failure risk score');
    }

    public function test_readings_endpoint_returns_a_reading_and_a_risk_score(): void
    {
        $this->getJson('/api/readings?scenario=overheating&tick=45')
            ->assertOk()
            ->assertJsonStructure([
                'scenario', 'tick',
                'reading' => ['air_temp_k', 'process_temp_k', 'rotational_speed_rpm', 'torque_nm', 'tool_wear_min', 'vibration_mm_s', 'run_hours'],
                'risk' => ['score', 'band', 'band_label', 'action', 'explanation', 'drivers'],
            ]);
    }

    public function test_dashboard_page_shows_temperature_vibration_and_run_hours(): void
    {
        $this->get('/')->assertOk();
        $this->getJson('/api/readings?scenario=overload&tick=59')
            ->assertOk()
            ->assertJsonPath('risk.band', 'high')
            ->assertJsonFragment(['key' => 'vibration'])
            ->assertJsonFragment(['key' => 'run_hours']);
    }

    public function test_readings_endpoint_rejects_an_unknown_scenario(): void
    {
        $this->getJson('/api/readings?scenario=nope&tick=0')->assertStatus(422);
    }
}
