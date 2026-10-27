<?php

namespace Tests\Feature;

use App\Models\OverrideLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverrideOwnershipTest extends TestCase
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
    }

    private function entry(?User $owner): OverrideLog
    {
        return OverrideLog::create([
            'user_id' => $owner?->id,
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

    /** @return array<string, string> */
    private function edit(): array
    {
        return ['operator_name' => 'New Name', 'reason' => 'A new reason that is long enough.'];
    }

    public function test_the_owner_can_view_edit_and_delete_their_entry(): void
    {
        $log = $this->entry($this->owner);
        $this->actingAs($this->owner);

        $this->getJson("/api/overrides/{$log->uuid}")->assertOk()->assertJsonPath('data.id', $log->uuid);
        $this->putJson("/api/overrides/{$log->uuid}", $this->edit())->assertOk()->assertJsonPath('data.operator_name', 'New Name');
        $this->assertSame('New Name', $log->fresh()->operator_name);
        $this->deleteJson("/api/overrides/{$log->uuid}")->assertNoContent();
        $this->assertSoftDeleted($log);
    }

    public function test_an_edit_keeps_the_original_and_a_delete_keeps_the_row_for_the_review_page(): void
    {
        $log = $this->entry($this->owner);
        $this->actingAs($this->owner);

        $this->putJson("/api/overrides/{$log->uuid}", $this->edit())->assertOk();
        $this->putJson("/api/overrides/{$log->uuid}", ['operator_name' => 'Third Name', 'reason' => 'A third reason that is long enough.'])->assertOk();

        $fresh = $log->fresh();
        $this->assertSame('Pat Rivera', $fresh->original_operator_name);
        $this->assertSame('Finishing the last part of the shift with a supervisor watching.', $fresh->original_reason);
        $this->assertNotNull($fresh->edited_at);

        $this->deleteJson("/api/overrides/{$log->uuid}")->assertNoContent();
        $this->getJson('/api/overrides')->assertJsonCount(0, 'data');

        $this->actingAs($this->adminUser())->get('/overrides')
            ->assertOk()
            ->assertSee('Third Name')
            ->assertSee('Original name: Pat Rivera')
            ->assertSee('Deleted by its owner');
    }

    public function test_another_user_gets_403_on_view_edit_and_delete_and_nothing_changes(): void
    {
        $log = $this->entry($this->owner);
        $this->actingAs($this->other);

        $this->getJson("/api/overrides/{$log->uuid}")->assertForbidden();
        $this->putJson("/api/overrides/{$log->uuid}", $this->edit())->assertForbidden();
        $this->deleteJson("/api/overrides/{$log->uuid}")->assertForbidden();

        $this->assertSame('Pat Rivera', $log->fresh()->operator_name);
    }

    public function test_an_entry_with_no_owner_is_forbidden_to_everyone(): void
    {
        $log = $this->entry(null);
        $this->actingAs($this->owner);

        $this->getJson("/api/overrides/{$log->uuid}")->assertForbidden();
        $this->putJson("/api/overrides/{$log->uuid}", $this->edit())->assertForbidden();
        $this->deleteJson("/api/overrides/{$log->uuid}")->assertForbidden();
        $this->assertModelExists($log);
    }

    public function test_the_list_holds_only_the_users_own_entries(): void
    {
        $mine = $this->entry($this->owner);
        $this->entry($this->other);
        $this->entry(null);

        $this->actingAs($this->owner)->getJson('/api/overrides')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->uuid);
    }

    public function test_a_guest_gets_401_and_nothing_changes(): void
    {
        $log = $this->entry($this->owner);

        $this->getJson('/api/overrides')->assertUnauthorized();
        $this->getJson("/api/overrides/{$log->uuid}")->assertUnauthorized();
        $this->putJson("/api/overrides/{$log->uuid}", $this->edit())->assertUnauthorized();
        $this->deleteJson("/api/overrides/{$log->uuid}")->assertUnauthorized();
        $this->assertModelExists($log);
    }

    public function test_a_demo_session_gets_401(): void
    {
        $log = $this->entry($this->owner);

        $this->withSession(['prism_demo' => true])->deleteJson("/api/overrides/{$log->uuid}")->assertUnauthorized();
        $this->assertModelExists($log);
    }

    public function test_a_missing_entry_is_404_and_a_bad_edit_is_400(): void
    {
        $log = $this->entry($this->owner);
        $this->actingAs($this->owner);

        $this->getJson('/api/overrides/0198a1c2-0000-7000-8000-000000000000')->assertNotFound();
        $this->putJson("/api/overrides/{$log->uuid}", ['operator_name' => 'x', 'reason' => 'short'])->assertStatus(400);
    }

    public function test_the_routes_do_not_exist_while_the_sign_in_is_off(): void
    {
        config(['prism.require_login' => false]);
        $log = $this->entry($this->owner);

        $this->actingAs($this->owner)->getJson("/api/overrides/{$log->uuid}")->assertNotFound();
    }
}
