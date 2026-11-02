<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RateLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route as Router;
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

    public function test_the_work_order_route_allows_ten_a_minute_like_the_diagnosis(): void
    {
        $this->signIn();

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/work-order'.self::WORN)->assertOk();
        }

        $this->getJson('/api/work-order'.self::WORN)->assertStatus(429);
    }

    public function test_the_override_route_allows_twenty_a_minute(): void
    {
        $this->signIn();
        $payload = [
            'operator_name' => 'Pat Rivera',
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'scenario' => 'overload',
            'tick' => 59,
        ];

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/overrides', $payload)->assertCreated();
        }

        $this->postJson('/api/overrides', $payload)->assertStatus(429);
        $this->assertDatabaseCount('override_logs', 20);
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
        config(['prism.require_login' => true]);
        $this->actingAs($this->adminUser());

        for ($i = 0; $i < 10; $i++) {
            $this->post('/admin/invites', ['email' => 'judge'.$i.'@example.test'])->assertSessionHasNoErrors();
        }

        $this->post('/admin/invites', ['email' => 'judge10@example.test'])->assertStatus(429);
    }

    public function test_a_429_carries_retry_after_and_the_limit_headers(): void
    {
        $this->freezeTime();
        config(['prism.require_login' => true]);
        $this->actingAs(User::factory()->create());

        for ($i = 0; $i < RateLimits::READ_PER_MINUTE; $i++) {
            $this->getJson('/api/readings?scenario=healthy&tick=0')
                ->assertOk()
                ->assertHeader('X-RateLimit-Limit', (string) RateLimits::READ_PER_MINUTE);
        }

        $this->getJson('/api/readings?scenario=healthy&tick=0')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '60')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertJson(['message' => 'Too many requests. Wait a minute and try again.']);

        // The limit resets after a minute.
        $this->travel(61)->seconds();
        $this->getJson('/api/readings?scenario=healthy&tick=0')->assertOk();
    }

    public function test_read_limits_count_per_user(): void
    {
        config(['prism.require_login' => true]);
        $first = User::factory()->create();
        $second = User::factory()->create();

        for ($i = 0; $i < RateLimits::READ_PER_MINUTE; $i++) {
            $this->actingAs($first)->getJson('/api/readings?scenario=healthy&tick=0')->assertOk();
        }

        $this->actingAs($first)->getJson('/api/readings?scenario=healthy&tick=0')->assertStatus(429);
        $this->actingAs($second)->getJson('/api/readings?scenario=healthy&tick=0')->assertOk();
    }

    public function test_your_own_override_list_allows_one_hundred_reads_a_minute(): void
    {
        config(['prism.require_login' => true]);
        $this->actingAs(User::factory()->create());

        for ($i = 0; $i < RateLimits::READ_PER_MINUTE; $i++) {
            $this->getJson('/api/overrides')->assertOk();
        }

        $this->getJson('/api/overrides')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_a_blocked_form_page_shows_the_styled_429_with_retry_after(): void
    {
        config(['prism.require_login' => true]);
        $form = ['token' => 'not-a-token', 'email' => 'judge@example.test'];

        for ($i = 0; $i < RateLimits::AUTH_PER_MINUTE; $i++) {
            $this->post('/unlock-account', $form)->assertRedirect('/login');
        }

        $this->post('/unlock-account', $form)->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_the_limits_stay_inside_the_agreed_ranges(): void
    {
        $this->assertTrue(RateLimits::AUTH_PER_MINUTE >= 5 && RateLimits::AUTH_PER_MINUTE <= 10);
        $this->assertTrue(RateLimits::AUTH_LINK_PER_MINUTE >= 5 && RateLimits::AUTH_LINK_PER_MINUTE <= 10);
        $this->assertTrue(RateLimits::READ_PER_MINUTE >= 60 && RateLimits::READ_PER_MINUTE <= 100);
        $this->assertTrue(RateLimits::WRITE_PER_MINUTE >= 20 && RateLimits::WRITE_PER_MINUTE <= 30);
        $this->assertTrue(RateLimits::UPLOAD_PER_MINUTE >= 5 && RateLimits::UPLOAD_PER_MINUTE <= 10);
    }

    /**
     * Every /api/ route and every route that changes data has a named limit.
     * A new route without one fails here.
     */
    public function test_every_api_and_write_route_has_a_named_rate_limit(): void
    {
        $named = ['auth', 'auth-link', 'invites', 'password-set', 'heavy-read', 'api-read', 'api-write', 'uploads', 'ai-diagnosis', 'ai-work-order'];
        $missing = [];

        /** @var Route $route */
        foreach (Router::getRoutes() as $route) {
            $writes = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [];
            if (! str_starts_with($route->uri(), 'api/') && ! $writes) {
                continue;
            }

            $throttles = array_filter($route->gatherMiddleware(), fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:'));
            $ok = array_filter($throttles, fn (string $m): bool => in_array(substr($m, strlen('throttle:')), $named, true));
            if ($ok === []) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $missing, 'Routes without a named rate limit');
    }
}
