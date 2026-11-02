<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\User;
use App\Notifications\AccountLocked;
use App\Notifications\JudgeInvite;
use App\Services\Ai\AiClient;
use App\Support\AiBudget;
use App\Support\RateLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Limits on the routes that cost money or run heavy work: the AI routes
 * (Anthropic API), the routes that send email (Amazon SES), the forms that ask
 * the Have I Been Pwned API, and the admin pages that read the most rows.
 */
class ExpensiveRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const WORN = '?scenario=worn_tool&tick=59';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Http::preventStrayRequests();
        config(['services.anthropic.key' => null]);
    }

    /** @return array<string, mixed> */
    private function apiReply(int $input, int $output): array
    {
        return [
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => $input, 'output_tokens' => $output],
        ];
    }

    public function test_the_ai_routes_allow_sixty_an_hour_per_user(): void
    {
        $this->signIn();

        for ($i = 0; $i < RateLimits::AI_PER_HOUR; $i++) {
            $this->getJson('/api/diagnosis'.self::WORN)->assertOk();
            $this->getJson('/api/work-order'.self::WORN)->assertOk();
            if ($i % RateLimits::AI_PER_MINUTE === RateLimits::AI_PER_MINUTE - 1) {
                $this->travel(61)->seconds();
            }
        }

        $this->getJson('/api/diagnosis'.self::WORN)->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/api/work-order'.self::WORN)->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_a_call_costs_its_tokens_at_the_configured_prices(): void
    {
        // Claude Haiku 5.5: $0.10 input and $0.50 output per million tokens.
        // 5,911 input and 1,377 output tokens = 591.1 + 688.5 = 1,279.6
        // millionths of a dollar, rounded up.
        $this->assertSame(1280, AiBudget::costMicros(5911, 1377));
    }

    public function test_every_api_reply_is_counted_against_the_budgets(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->apiReply(5911, 1377))]);
        $client = new AiClient('test-key', 'claude-haiku-5-5');

        $this->assertNotNull($client->complete('system', 'user', 100));

        $this->assertEqualsWithDelta(0.00128, AiBudget::siteSpentUsd(), 0.0000001);
    }

    public function test_a_spent_user_budget_answers_429_and_another_user_still_gets_answers(): void
    {
        config(['prism.ai_budget.user_hourly_usd' => 0.001]);
        $first = User::factory()->create();
        $second = User::factory()->create();

        // Spend $0.001 as the first user, the way AiClient does inside a request.
        request()->setUserResolver(fn () => $first);
        AiBudget::record(10_000, 0);
        request()->setUserResolver(fn () => null);

        $this->actingAs($first)->getJson('/api/diagnosis'.self::WORN)
            ->assertStatus(429)
            ->assertHeader('Retry-After', '3600')
            ->assertJson(['message' => 'You have used your AI budget for this hour. Try again later.']);

        $this->actingAs($second)->getJson('/api/diagnosis'.self::WORN)->assertOk();

        $this->travel(61)->minutes();
        $this->actingAs($first)->getJson('/api/diagnosis'.self::WORN)->assertOk();
    }

    public function test_a_spent_site_budget_stops_every_user_for_the_rest_of_the_day(): void
    {
        config(['prism.ai_budget.daily_usd' => 0.001]);
        $this->actingAs(User::factory()->create());
        AiBudget::record(10_000, 0);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/work-order'.self::WORN)
            ->assertStatus(429)
            ->assertHeader('Retry-After', '86400')
            ->assertJson(['message' => 'The AI budget for today is used up. Try again later.']);
    }

    public function test_a_spent_budget_stops_the_api_call_before_it_is_made(): void
    {
        Http::fake();
        config(['prism.ai_budget.daily_usd' => 0.001]);
        AiBudget::record(10_000, 0);
        $client = new AiClient('test-key', 'claude-haiku-5-5');

        $this->assertNull($client->complete('system', 'user', 100));
        $this->assertSame('budget', $client->failure());
        Http::assertNothingSent();
    }

    public function test_invites_send_at_most_ten_emails_an_hour_per_admin(): void
    {
        Notification::fake();
        config(['prism.require_login' => true, 'prism.admin.email' => 'admin@example.test']);
        $admin = User::factory()->create(['email' => 'admin@example.test']);

        for ($i = 1; $i <= RateLimits::EMAILS_PER_HOUR; $i++) {
            // A new address each time, so the 3 new accounts per address limit does not apply.
            $this->actingAs($admin)
                ->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->post('/admin/invites', ['email' => 'judge'.$i.'@example.test'])
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->post('/admin/invites', ['email' => 'judge99@example.test'])
            ->assertStatus(429)
            ->assertHeader('Retry-After', '3600');
        Notification::assertSentTimes(JudgeInvite::class, RateLimits::EMAILS_PER_HOUR);
    }

    public function test_the_site_wide_email_cap_stops_invites(): void
    {
        Notification::fake();
        config(['prism.require_login' => true, 'prism.email_daily_cap' => 2]);

        foreach (['a', 'b'] as $name) {
            $this->actingAs($this->adminUser())->post('/admin/invites', ['email' => $name.'@example.test'])->assertSessionHasNoErrors();
        }

        $this->actingAs($this->adminUser())->post('/admin/invites', ['email' => 'c@example.test'])
            ->assertStatus(429)
            ->assertHeader('Retry-After', '86400');
        $this->assertDatabaseMissing('users', ['email' => 'c@example.test']);
    }

    public function test_the_email_cap_skips_the_lock_email_but_the_account_still_locks(): void
    {
        Notification::fake();
        config(['prism.require_login' => true, 'prism.email_daily_cap' => 1]);
        $this->actingAs($this->adminUser())->post('/admin/invites', ['email' => 'judge@example.test'])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();

        $owner = User::factory()->create(['email' => 'owner@example.test']);
        for ($i = 1; $i <= AuthController::MAX_FAILURES_PER_ACCOUNT; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->post('/login', ['email' => $owner->email, 'password' => 'wrong']);
        }

        $this->assertTrue($owner->fresh()->isLocked());
        Notification::assertNotSentTo($owner, AccountLocked::class);
    }

    public function test_password_forms_that_ask_the_breach_api_allow_twenty_an_hour_per_address(): void
    {
        config(['prism.require_login' => true]);

        for ($i = 1; $i <= RateLimits::PASSWORD_SET_PER_HOUR; $i++) {
            // A new email each time, so the wrong-token limit per email does not apply.
            $this->post('/set-password', ['token' => 'guess', 'email' => 'judge'.$i.'@example.test', 'password' => 'x', 'password_confirmation' => 'x'])
                ->assertStatus(302);
            if ($i % RateLimits::AUTH_PER_MINUTE === 0) {
                $this->travel(61)->seconds();
            }
        }

        $this->post('/set-password', ['token' => 'guess', 'email' => 'judge99@example.test'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
        $this->post('/unlock-account/password', ['token' => 'guess', 'email' => 'judge99@example.test'])
            ->assertStatus(302);
    }

    public function test_the_override_review_page_allows_thirty_a_minute(): void
    {
        config(['prism.require_login' => true]);
        $this->actingAs($this->adminUser());

        for ($i = 0; $i < RateLimits::HEAVY_READ_PER_MINUTE; $i++) {
            $this->get('/overrides')->assertOk();
        }

        $this->get('/overrides')->assertStatus(429)->assertHeader('Retry-After');
    }
}
