<?php

namespace Tests\Feature;

use App\Models\OverrideLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AccessHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_override_records_the_signed_in_account(): void
    {
        $user = User::factory()->create();
        config(['prism.require_login' => true]);

        $this->actingAs($user)->postJson('/api/overrides', [
            'operator_name' => 'Someone Else',
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'scenario' => 'overload',
            'tick' => 59,
        ])->assertCreated();

        $this->assertSame($user->id, OverrideLog::sole()->user_id);
    }

    public function test_the_daily_ai_cap_stops_both_ai_routes(): void
    {
        RateLimiter::clear('ai-daily-cap');
        config(['prism.ai_daily_cap' => 2]);

        for ($i = 0; $i < 2; $i++) {
            $this->getJson('/api/diagnosis?scenario=healthy&tick='.$i)->assertSuccessful();
        }

        $this->getJson('/api/diagnosis?scenario=healthy&tick=5')->assertStatus(429);
        $this->getJson('/api/work-order?scenario=healthy&tick=5')->assertStatus(429);
        $this->getJson('/api/readings?scenario=healthy&tick=5')->assertOk();
    }

    public function test_the_readings_route_is_throttled(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/readings?scenario=healthy&tick='.$i)->assertOk();
        }

        $this->getJson('/api/readings?scenario=healthy&tick=999')->assertStatus(429);
    }

    public function test_dashboard_polling_does_not_use_up_the_override_and_ai_limits(): void
    {
        config(['prism.require_login' => true]);
        $this->actingAs(User::factory()->create());

        // The dashboard asks for a reading every 1.2 seconds, about 50 a minute.
        for ($i = 0; $i < 100; $i++) {
            $this->getJson('/api/readings?scenario=overload&tick='.($i % 60))->assertOk();
        }

        $this->postJson('/api/overrides', [
            'operator_name' => 'Pat Rivera',
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'scenario' => 'overload',
            'tick' => 59,
        ])->assertCreated();
        $this->getJson('/api/diagnosis?scenario=overload&tick=59')->assertSuccessful();
        $this->postJson('/api/ai-feedback', [
            'step' => 'diagnosis', 'rating' => 'helpful', 'source' => 'ai', 'scenario' => 'overload', 'tick' => 59,
        ])->assertCreated();
    }
}
