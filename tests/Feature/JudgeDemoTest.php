<?php

namespace Tests\Feature;

use App\Models\AiFeedback;
use App\Models\OverrideLog;
use App\Models\User;
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
        $this->get('/')->assertOk()->assertSee('Read-only demo');
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

    public function test_the_demo_link_does_nothing_when_the_sign_in_is_off(): void
    {
        config(['prism.require_login' => false]);
        $this->get('/demo')->assertRedirect('/');
        $this->get('/')->assertOk()->assertDontSee('Read-only demo');
    }
}
