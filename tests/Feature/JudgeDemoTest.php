<?php

namespace Tests\Feature;

use App\Models\AiFeedback;
use App\Models\OverrideLog;
use App\Models\User;
use App\Services\RiskScorer;
use App\Services\SensorSimulator;
use App\Support\DemoSamples;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JudgeDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true]);
    }

    public function test_the_login_page_has_a_judge_demo_button(): void
    {
        $this->get('/login')->assertOk()->assertSee('Open the Judge Demo')->assertSee(url('/demo'), false);
    }

    public function test_the_demo_link_opens_the_dashboard_without_an_account(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/demo')->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee('Read-only Demo');
        $this->getJson('/api/readings?scenario=healthy&tick=0')->assertOk();
    }

    public function test_the_demo_link_works_more_than_once(): void
    {
        $this->get('/demo')->assertRedirect('/');
        $this->get('/demo')->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    public function test_the_demo_cannot_write_overrides_or_ratings(): void
    {
        $this->get('/demo');

        $this->postJson('/api/overrides', [
            'operator_name' => 'Test Operator',
            'reason' => 'Keeping the machine running for a test.',
            'scenario' => 'worn_tool',
            'tick' => 59,
        ])->assertStatus(403)->assertJson(['message' => 'This is the read-only demo. Nothing is saved.']);

        $this->postJson('/api/ai-feedback', [
            'step' => 'diagnosis',
            'rating' => 'helpful',
            'source' => 'rules',
            'scenario' => 'worn_tool',
            'tick' => 59,
        ])->assertStatus(403);

        $this->assertSame(0, OverrideLog::count());
        $this->assertSame(0, AiFeedback::count());
    }

    public function test_a_visitor_who_never_opened_the_demo_is_still_blocked(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/api/readings?scenario=healthy&tick=0')->assertStatus(401);
        $this->postJson('/api/overrides', [])->assertStatus(401);
    }

    public function test_exit_demo_ends_the_demo_session(): void
    {
        $this->get('/demo');
        $this->post('/logout')->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
    }

    public function test_a_signed_in_user_can_still_write(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/demo');

        $this->actingAs($user)->postJson('/api/ai-feedback', [
            'step' => 'diagnosis',
            'rating' => 'helpful',
            'source' => 'rules',
            'scenario' => 'worn_tool',
            'tick' => 59,
        ])->assertStatus(201);
    }

    public function test_the_guide_opens_on_every_load_in_the_demo(): void
    {
        $this->get('/demo');

        $this->get('/')->assertOk()->assertSee('data-open-every-load="1"', false);
    }

    public function test_the_guide_stays_once_per_session_for_a_signed_in_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('id="guide-modal"', false)
            ->assertDontSee('data-open-every-load', false);
    }

    public function test_the_demo_can_open_a_sample_override_log_without_a_password(): void
    {
        config(['prism.override_log_password' => $this->fixturePassword('secret')]);
        $this->get('/demo');

        $this->get('/overrides')
            ->assertOk()
            ->assertSee('Sample data, read only.')
            ->assertSee('Sample Operator A')
            ->assertSee('Override Log (sample)')
            ->assertSee('Recent AI ratings');
    }

    public function test_the_sample_override_log_works_with_no_password_set(): void
    {
        config(['prism.override_log_password' => '']);
        $this->get('/demo');
        $this->get('/overrides')->assertOk()->assertSee('Sample data, read only.');
    }

    public function test_the_sample_override_log_never_shows_real_entries(): void
    {
        OverrideLog::create([
            'operator_name' => 'Real Person Zed',
            'reason' => 'A real reason that must stay private.',
            'machine' => 'Milling Machine M-01',
            'scenario' => 'overload',
            'tick' => 59,
            'risk_score' => 90,
            'action_shown' => 'Stop the machine and inspect it now.',
            'reading' => [],
        ]);

        $this->get('/demo');
        $this->get('/overrides')
            ->assertOk()
            ->assertDontSee('Real Person Zed')
            ->assertDontSee('A real reason that must stay private.');
    }

    public function test_the_sample_rows_are_all_high_readings_scored_by_the_real_scorer(): void
    {
        $rows = DemoSamples::overrides();
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $risk = (new RiskScorer('L'))
                ->score((new SensorSimulator)->reading($row['scenario'], $row['tick']));
            $this->assertSame('high', $risk['band'], $row['scenario']);
            $this->assertSame($risk['score'], $row['risk_score']);
        }
    }

    public function test_the_override_log_still_needs_its_password_without_the_demo(): void
    {
        config(['prism.override_log_password' => $this->fixturePassword('secret')]);

        $this->get('/overrides')->assertStatus(401);
        $this->get('/demo');
        $this->get('/overrides')->assertOk();
        $this->post('/logout');
        $this->get('/overrides')->assertStatus(401);
    }

    public function test_a_signed_in_user_without_the_password_does_not_get_the_sample(): void
    {
        config(['prism.override_log_password' => $this->fixturePassword('secret')]);
        $user = User::factory()->create();
        $this->actingAs($user)->get('/demo');

        $this->actingAs($user)->get('/overrides')->assertStatus(401);
    }

    public function test_the_demo_link_does_nothing_when_the_sign_in_is_off(): void
    {
        config(['prism.require_login' => false]);
        $this->get('/demo')->assertRedirect('/');
        $this->get('/')->assertOk()->assertDontSee('Read-only Demo');
    }
}
