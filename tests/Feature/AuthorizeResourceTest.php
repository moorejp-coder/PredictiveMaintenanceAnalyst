<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthorizeResource;
use App\Models\OverrideLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthorizeResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true]);
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();

        AuthorizeResource::define('owns-override', fn (User $user, OverrideLog $log) => $log->user_id === $user->id);

        Route::middleware(['web', 'authorize.resource:owns-override,overrideLog'])
            ->get('/__guarded/{overrideLog}', fn (OverrideLog $overrideLog) => response()->json(['id' => $overrideLog->uuid]));
        Route::middleware(['web', 'authorize.resource:nothing-defined,overrideLog'])
            ->get('/__unknown/{overrideLog}', fn () => 'reached');
    }

    private function entry(): OverrideLog
    {
        return OverrideLog::create([
            'user_id' => $this->owner->id,
            'operator_name' => 'Pat Rivera',
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'machine' => 'Milling Machine M-01',
            'scenario' => 'overload',
            'tick' => 59,
            'risk_score' => 90,
            'action_shown' => 'Stop the machine and inspect it now.',
            'reading' => ['torque_nm' => 50],
        ]);
    }

    public function test_the_check_function_allows_the_owner(): void
    {
        $log = $this->entry();

        $this->actingAs($this->owner)->getJson("/__guarded/{$log->uuid}")->assertOk()->assertJsonPath('id', $log->uuid);
    }

    public function test_a_guest_gets_401_and_a_notice_is_logged(): void
    {
        Log::spy();
        $log = $this->entry();

        $this->getJson("/__guarded/{$log->uuid}")->assertUnauthorized();

        Log::shouldHaveReceived('log')->once()->withArgs(
            fn ($level, $message, $context) => $level === 'notice' && $context['user_id'] === null && $context['resource_id'] === $log->uuid
        );
    }

    public function test_a_signed_in_non_owner_gets_403_and_a_warning_is_logged(): void
    {
        Log::spy();
        $log = $this->entry();

        $this->actingAs($this->other)->getJson("/__guarded/{$log->uuid}")->assertForbidden();

        Log::shouldHaveReceived('log')->once()->withArgs(
            fn ($level, $message, $context) => $level === 'warning'
                && $context['user_id'] === $this->other->id
                && $context['resource_id'] === $log->uuid
                && $context['resource_param'] === 'overrideLog'
                && ! array_key_exists('query', $context)
        );
    }

    public function test_a_check_name_with_no_function_or_policy_ability_denies(): void
    {
        $log = $this->entry();

        $this->actingAs($this->owner)->getJson("/__unknown/{$log->uuid}")->assertForbidden();
    }

    public function test_the_existing_delete_route_uses_the_gate_and_logs_a_refusal(): void
    {
        Log::spy();
        $log = $this->entry();

        $this->actingAs($this->other)->deleteJson("/api/overrides/{$log->uuid}")->assertForbidden();
        $this->assertModelExists($log);
        Log::shouldHaveReceived('log')->once()->withArgs(fn ($level, $message, $context) => $level === 'warning' && $context['method'] === 'DELETE');

        $this->actingAs($this->owner)->deleteJson("/api/overrides/{$log->uuid}")->assertNoContent();
        $this->assertSoftDeleted($log);
    }
}
