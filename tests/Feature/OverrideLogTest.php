<?php

namespace Tests\Feature;

use App\Models\OverrideLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverrideLogTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'operator_name' => 'Pat Rivera',
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'scenario' => 'overload',
            'tick' => 59,
        ], $overrides);
    }

    public function test_an_override_is_recorded_with_who_when_and_why(): void
    {
        $this->postJson('/api/overrides', $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['id', 'recorded_at']);

        $log = OverrideLog::sole();
        $this->assertSame('Pat Rivera', $log->operator_name);
        $this->assertSame('Finishing the last part of the shift with a supervisor watching.', $log->reason);
        $this->assertSame('overload', $log->scenario);
        $this->assertSame(59, $log->tick);
        $this->assertSame('Stop the machine and inspect it now.', $log->action_shown);
        $this->assertGreaterThanOrEqual(70, $log->risk_score);
        $this->assertNotNull($log->created_at);
        $this->assertArrayHasKey('torque_nm', $log->reading);
    }

    public function test_the_server_rebuilds_the_score_and_ignores_one_sent_by_the_browser(): void
    {
        $this->postJson('/api/overrides', $this->payload(['risk_score' => 1, 'action_shown' => 'fake']))
            ->assertCreated();

        $this->assertNotSame(1, OverrideLog::sole()->risk_score);
        $this->assertSame('Stop the machine and inspect it now.', OverrideLog::sole()->action_shown);
    }

    public function test_a_low_risk_reading_cannot_be_logged_as_an_override(): void
    {
        $this->postJson('/api/overrides', $this->payload(['scenario' => 'healthy', 'tick' => 3]))
            ->assertStatus(422);

        $this->assertSame(0, OverrideLog::count());
    }

    public function test_name_and_reason_are_required(): void
    {
        $this->postJson('/api/overrides', $this->payload(['operator_name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('operator_name');

        $this->postJson('/api/overrides', $this->payload(['reason' => 'too short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(0, OverrideLog::count());
    }

    public function test_an_unknown_scenario_is_rejected(): void
    {
        $this->postJson('/api/overrides', $this->payload(['scenario' => 'nope']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('scenario');
    }

    public function test_the_dashboard_carries_the_override_button_and_a_csrf_token(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Keep running anyway')
            ->assertSee('name="csrf-token"', false)
            ->assertSee(url('/api/overrides'), false);
    }

    public function test_the_review_page_does_not_exist_until_a_password_is_set(): void
    {
        config(['prism.override_log_password' => null]);

        $this->get('/overrides')->assertNotFound();
    }

    public function test_the_review_page_asks_for_the_password(): void
    {
        config(['prism.override_log_password' => 'open-sesame']);

        $this->get('/overrides')->assertStatus(401);
        $this->get('/overrides', ['Authorization' => 'Basic '.base64_encode('judge:wrong')])->assertStatus(401);
    }

    public function test_the_review_page_lists_entries_with_the_right_password(): void
    {
        config(['prism.override_log_password' => 'open-sesame']);
        $this->postJson('/api/overrides', $this->payload())->assertCreated();

        $this->get('/overrides', ['Authorization' => 'Basic '.base64_encode('judge:open-sesame')])
            ->assertOk()
            ->assertSee('Override Log')
            ->assertSee('Pat Rivera')
            ->assertSee('Finishing the last part of the shift')
            ->assertSee('noindex', false);
    }

    public function test_names_and_reasons_are_escaped_on_the_review_page(): void
    {
        config(['prism.override_log_password' => 'open-sesame']);
        $this->postJson('/api/overrides', $this->payload(['operator_name' => '<script>alert(1)</script>']))
            ->assertCreated();

        $this->get('/overrides', ['Authorization' => 'Basic '.base64_encode('judge:open-sesame')])
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
