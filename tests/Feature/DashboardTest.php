<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

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

    public function test_rotational_speed_is_in_the_driver_breakdown_but_not_scored(): void
    {
        $this->getJson('/api/readings?scenario=overheating&tick=59')
            ->assertOk()
            ->assertJsonFragment(['key' => 'speed', 'label' => 'Rotational speed', 'scored' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('context only and is not scored');
    }

    public function test_readings_endpoint_is_cacheable_and_sets_no_cookies(): void
    {
        $response = $this->getJson('/api/readings?scenario=healthy&tick=3')->assertOk();

        $this->assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $response->assertHeaderMissing('Set-Cookie');
    }

    public function test_readings_endpoint_rejects_an_unknown_scenario(): void
    {
        $this->getJson('/api/readings?scenario=nope&tick=0')->assertStatus(422);
    }

    public function test_terms_page_loads(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertSee('PRISM Terms and Conditions')
            ->assertSee('does not replace an inspection');
    }

    public function test_dashboard_footer_links_to_terms_and_privacy(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(url('/terms'), false)
            ->assertSee(url('/privacy'), false)
            ->assertSee(url('/faq'), false);
    }

    public function test_faq_page_loads_and_covers_the_six_governance_areas(): void
    {
        $this->get('/faq')
            ->assertOk()
            ->assertSee('PRISM FAQ')
            ->assertSee('In-scope definition')
            ->assertSee('Out-of-scope risk control')
            ->assertSee('Escalation rule')
            ->assertSee('Ownership and maintenance')
            ->assertSee('Lifecycle reality')
            ->assertSee('SLA posture')
            ->assertSee('does not replace an inspection');
    }

    public function test_dashboard_gives_the_chart_a_text_alternative_and_hides_decoration(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('id="chart"', false)
            ->assertSee('role="img"', false)
            ->assertSee('aria-label="Line chart of the risk score', false)
            ->assertSee('aria-describedby="cycle"', false)
            ->assertSee('<svg viewBox="0 0 120 120" aria-hidden="true">', false);
    }
}
