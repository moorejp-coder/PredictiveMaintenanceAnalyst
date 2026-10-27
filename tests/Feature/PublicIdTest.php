<?php

namespace Tests\Feature;

use App\Models\AiFeedback;
use App\Models\OverrideLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicIdTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'operator_name' => 'Pat Rivera',
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'scenario' => 'overload',
            'tick' => 59,
        ];
    }

    public function test_a_new_override_response_carries_a_uuid_not_the_row_number(): void
    {
        config(['prism.require_login' => true]);
        $user = User::factory()->create();

        $id = $this->actingAs($user)->postJson('/api/overrides', $this->payload())->assertCreated()->json('id');

        $this->assertTrue(Str::isUuid($id));
        $this->assertSame($id, OverrideLog::sole()->uuid);
        $this->assertNotSame((string) OverrideLog::sole()->id, $id);
    }

    public function test_a_rating_response_carries_a_uuid(): void
    {
        $id = $this->actingAs(User::factory()->create())->postJson('/api/ai-feedback', [
            'step' => 'diagnosis', 'rating' => 'helpful', 'source' => 'ai', 'scenario' => 'overload', 'tick' => 59,
        ])->assertCreated()->json('id');

        $this->assertTrue(Str::isUuid($id));
        $this->assertSame($id, AiFeedback::sole()->uuid);
    }

    public function test_each_row_gets_its_own_uuid(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        config(['prism.require_login' => true]);

        $first = $this->postJson('/api/overrides', $this->payload())->json('id');
        $second = $this->postJson('/api/overrides', $this->payload())->json('id');

        $this->assertNotSame($first, $second);
    }

    public function test_the_list_and_detail_responses_hold_only_uuids(): void
    {
        config(['prism.require_login' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $uuid = $this->postJson('/api/overrides', $this->payload())->json('id');

        $this->getJson('/api/overrides')->assertJsonPath('data.0.id', $uuid);
        $this->getJson("/api/overrides/{$uuid}")->assertOk()->assertJsonPath('data.id', $uuid);
    }

    public function test_the_old_row_number_in_the_url_no_longer_works(): void
    {
        config(['prism.require_login' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->postJson('/api/overrides', $this->payload())->assertCreated();
        $rowNumber = OverrideLog::sole()->id;

        $this->getJson("/api/overrides/{$rowNumber}")->assertNotFound();
        $this->putJson("/api/overrides/{$rowNumber}", ['operator_name' => 'New Name', 'reason' => 'A new reason that is long enough.'])->assertNotFound();
        $this->deleteJson("/api/overrides/{$rowNumber}")->assertNotFound();
        $this->assertModelExists(OverrideLog::sole());
    }
}
