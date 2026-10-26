<?php

namespace Tests\Feature;

use App\Models\OverrideLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OverrideLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

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
            ->assertStatus(400)
            ->assertJsonValidationErrors('operator_name');

        $this->postJson('/api/overrides', $this->payload(['reason' => 'too short']))
            ->assertStatus(400)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(0, OverrideLog::count());
    }

    public function test_an_unknown_scenario_is_rejected(): void
    {
        $this->postJson('/api/overrides', $this->payload(['scenario' => 'nope']))
            ->assertStatus(400)
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
        config(['prism.override_log_password' => $this->fixturePassword('override')]);

        $this->get('/overrides')->assertStatus(401);
        $this->get('/overrides', ['Authorization' => 'Basic '.base64_encode('judge:wrong')])->assertStatus(401);
    }

    public function test_the_review_page_lists_entries_with_the_right_password(): void
    {
        config(['prism.override_log_password' => $this->fixturePassword('override')]);
        $this->postJson('/api/overrides', $this->payload())->assertCreated();

        $this->get('/overrides', ['Authorization' => 'Basic '.base64_encode('judge:'.$this->fixturePassword('override'))])
            ->assertOk()
            ->assertSee('Override Log')
            ->assertSee('Pat Rivera')
            ->assertSee('Finishing the last part of the shift')
            ->assertSee('noindex', false);
    }

    public function test_names_and_reasons_are_escaped_on_the_review_page(): void
    {
        config(['prism.override_log_password' => $this->fixturePassword('override')]);
        $this->postJson('/api/overrides', $this->payload(['operator_name' => '<b>Pat</b> & "Co"']))
            ->assertCreated();

        $this->get('/overrides', ['Authorization' => 'Basic '.base64_encode('judge:'.$this->fixturePassword('override'))])
            ->assertOk()
            ->assertDontSee('<b>Pat</b>', false)
            ->assertSee('&lt;b&gt;Pat&lt;/b&gt;', false);
    }

    public function test_control_characters_are_removed_before_a_name_and_reason_are_stored(): void
    {
        $this->postJson('/api/overrides', $this->payload([
            'operator_name' => "Pat\0 \nRivera",
            'reason' => "Finishing the shift\0 with a\r\nsupervisor watching.",
        ]))->assertCreated();

        $log = OverrideLog::sole();
        $this->assertSame('Pat Rivera', $log->operator_name);
        $this->assertSame("Finishing the shift with a\nsupervisor watching.", $log->reason);
    }

    public function test_text_that_is_not_valid_utf8_or_not_a_string_is_refused(): void
    {
        $this->postJson('/api/overrides', $this->payload(['operator_name' => ['Pat']]))->assertStatus(400);
        $this->post('/api/overrides', $this->payload(['reason' => "bad \xC3\x28 bytes in the reason"]), ['Accept' => 'application/json'])
            ->assertStatus(400);
        $this->assertSame(0, OverrideLog::count());
    }

    public function test_the_review_page_lists_ai_ratings_and_their_count(): void
    {
        config(['prism.override_log_password' => $this->fixturePassword('override')]);
        foreach (['diagnosis', 'work_order'] as $step) {
            $this->postJson('/api/ai-feedback', [
                'step' => $step, 'rating' => 'helpful', 'source' => 'ai', 'scenario' => 'overload', 'tick' => 59,
            ])->assertCreated();
        }

        $this->get('/overrides', ['Authorization' => 'Basic '.base64_encode('judge:'.$this->fixturePassword('override'))])
            ->assertOk()
            ->assertSee('2 Helpful, 0 Wrong')
            ->assertSee('Recent AI ratings')
            ->assertSee('work_order')
            ->assertSee('Overload, reading 60 of 60');
    }

    public function test_the_review_page_says_so_when_the_ratings_table_is_missing(): void
    {
        config(['prism.override_log_password' => $this->fixturePassword('override')]);
        Schema::drop('ai_feedback');

        $this->get('/overrides', ['Authorization' => 'Basic '.base64_encode('judge:'.$this->fixturePassword('override'))])
            ->assertOk()
            ->assertSee('The ratings could not be read')
            ->assertDontSee('0 Helpful');
    }
}
