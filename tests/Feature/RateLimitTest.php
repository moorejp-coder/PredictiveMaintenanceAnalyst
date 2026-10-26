<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Each limited route lets its allowed number of requests through and refuses
 * the next one with 429. Each test uses one route, because the limits count
 * per visitor.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const WORN = '?scenario=worn_tool&tick=59';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null]);
        Http::preventStrayRequests();
    }

    public function test_the_diagnosis_route_allows_ten_a_minute(): void
    {
        $this->signIn();

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/diagnosis'.self::WORN)->assertOk();
        }

        $this->getJson('/api/diagnosis'.self::WORN)->assertStatus(429);
    }

    public function test_the_work_order_route_allows_thirty_a_minute(): void
    {
        $this->signIn();

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/work-order'.self::WORN)->assertOk();
        }

        $this->getJson('/api/work-order'.self::WORN)->assertStatus(429);
    }

    public function test_the_override_route_allows_ten_a_minute(): void
    {
        $this->signIn();
        $payload = [
            'operator_name' => 'Pat Rivera',
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'scenario' => 'overload',
            'tick' => 59,
        ];

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/overrides', $payload)->assertCreated();
        }

        $this->postJson('/api/overrides', $payload)->assertStatus(429);
        $this->assertDatabaseCount('override_logs', 10);
    }

    public function test_the_rating_route_allows_twenty_a_minute(): void
    {
        $this->signIn();
        $payload = ['step' => 'diagnosis', 'rating' => 'helpful', 'source' => 'ai', 'scenario' => 'overload', 'tick' => 59];

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/ai-feedback', $payload)->assertCreated();
        }

        $this->postJson('/api/ai-feedback', $payload)->assertStatus(429);
        $this->assertDatabaseCount('ai_feedback', 20);
    }

    public function test_the_set_password_form_allows_five_tries_a_minute(): void
    {
        config(['prism.require_login' => true]);
        $form = [
            'token' => 'not-a-token',
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('judge'),
            'password_confirmation' => $this->fixturePassword('judge'),
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->post('/set-password', $form)->assertSessionHasErrors('email');
        }

        $this->post('/set-password', $form)->assertStatus(429);
    }

    public function test_the_invite_form_allows_ten_tries_a_minute(): void
    {
        Notification::fake();
        config(['prism.require_login' => true, 'prism.invite_admin_password' => $this->fixturePassword('admin')]);
        $headers = ['Authorization' => 'Basic '.base64_encode('entrant:'.$this->fixturePassword('admin'))];

        for ($i = 0; $i < 10; $i++) {
            $this->post('/admin/invites', ['email' => 'judge'.$i.'@example.test'], $headers)->assertSessionHasNoErrors();
        }

        $this->post('/admin/invites', ['email' => 'judge10@example.test'], $headers)->assertStatus(429);
    }
}
