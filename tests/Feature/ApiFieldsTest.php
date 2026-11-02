<?php

namespace Tests\Feature;

use App\Models\AiFeedback;
use App\Models\OverrideLog;
use App\Models\User;
use App\Support\ApiFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Each JSON response carries exactly the fields in its allowlist and nothing
 * else: no password hash, email address, database row number, owner id, admin
 * flag, stored sensor reading or other user's data.
 */
class ApiFieldsTest extends TestCase
{
    use RefreshDatabase;

    /** What the override API may send for one entry. */
    private const OVERRIDE_FIELDS = ['created_at', 'id', 'machine', 'operator_name', 'reason', 'risk_score', 'scenario', 'tick'];

    /** Words that must never appear in any response body. */
    private const FORBIDDEN = ['password', 'remember_token', 'email', 'is_admin', 'isAdmin', 'user_id', 'action_shown', 'original_', 'edited_at', 'deleted_at', 'updated_at'];

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

    /** @param  array<mixed>  $data */
    private function keys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }

    private function assertNoForbiddenWords(string $body): void
    {
        foreach (self::FORBIDDEN as $word) {
            $this->assertStringNotContainsString($word, $body, "Response holds '{$word}'.");
        }
    }

    public function test_pick_keeps_only_listed_fields_at_every_level(): void
    {
        $shape = ['a', 'obj' => ['x'], 'rows' => ['*' => ['k']], 'nothing' => ['z']];
        $data = [
            'a' => 1, 'secret' => 'hash',
            'obj' => ['x' => 1, 'y' => 'hidden'],
            'rows' => [['k' => 1, 'extra' => 2], ['k' => 3, 'extra' => 4]],
            'nothing' => null,
        ];

        $this->assertSame(
            ['a' => 1, 'obj' => ['x' => 1], 'rows' => [['k' => 1], ['k' => 3]], 'nothing' => null],
            ApiFields::pick($data, $shape),
        );
    }

    public function test_readings_response_has_only_the_allowed_fields(): void
    {
        $json = $this->getJson('/api/readings?scenario=overload&tick=40')->assertOk()->json();

        $this->assertSame(['estimate', 'reading', 'risk', 'scenario', 'tick'], $this->keys($json));
        $this->assertSame(['action', 'band', 'band_label', 'drivers', 'explanation', 'score'], $this->keys($json['risk']));
        $this->assertSame(['key', 'label', 'scored', 'stress'], $this->keys($json['risk']['drivers'][0]));
        $this->assertSame(['percent', 'probability', 'range', 'range_failed', 'range_records'], $this->keys($json['estimate']));
        $this->assertSame(
            ['air_temp_k', 'process_temp_k', 'rotational_speed_rpm', 'run_hours', 'tool_wear_min', 'torque_nm', 'vibration_mm_s'],
            $this->keys($json['reading']),
        );
    }

    public function test_ai_responses_have_only_the_allowed_fields(): void
    {
        config(['services.anthropic.key' => '']);

        $diagnosis = $this->getJson('/api/diagnosis?scenario=overload&tick=40')->assertOk()->json();
        $this->assertEmpty(array_diff(array_keys($diagnosis), ['source', 'reason', 'tokens', 'data', 'trace']));
        $this->assertEmpty(array_diff(array_keys($diagnosis['data']), ['cause', 'checks', 'evidence', 'tested_fix']));

        $order = $this->getJson('/api/work-order?scenario=overload&tick=40')->assertOk()->json();
        $this->assertEmpty(array_diff(array_keys($order), ['source', 'reason', 'tokens', 'data']));
        $this->assertSame(['priority', 'steps', 'title'], $this->keys($order['data']));
    }

    public function test_ai_shapes_drop_fields_a_service_adds_later(): void
    {
        $result = [
            'source' => 'ai', 'reason' => null, 'tokens' => ['input' => 1, 'output' => 2, 'model' => 'x', 'key_tail' => 'abcd'],
            'data' => ['cause' => 'c', 'checks' => ['a'], 'evidence' => ['t'], 'tested_fix' => null, 'raw_prompt' => 'secret'],
            'trace' => [['tool' => 't', 'summary' => 's', 'ok' => true, 'args' => ['x' => 1]]],
            'detail' => 'internal',
        ];

        $out = ApiFields::pick($result, ApiFields::DIAGNOSIS);

        $this->assertSame(['input' => 1, 'output' => 2], $out['tokens']);
        $this->assertArrayNotHasKey('raw_prompt', $out['data']);
        $this->assertArrayNotHasKey('detail', $out);
        $this->assertSame([['tool' => 't', 'summary' => 's', 'ok' => true]], $out['trace']);
    }

    public function test_override_api_never_sends_owner_row_number_or_other_users_data(): void
    {
        config(['prism.require_login' => true]);
        $me = User::factory()->create(['email' => 'me@example.test']);
        $other = User::factory()->create(['email' => 'other@example.test']);
        $mine = $this->entry($me);
        $this->entry($other);
        $this->actingAs($me);

        $list = $this->getJson('/api/overrides')->assertOk();
        $list->assertJsonCount(1, 'data');
        $this->assertSame(self::OVERRIDE_FIELDS, $this->keys($list->json('data.0')));
        $this->assertNoForbiddenWords($list->getContent());
        $this->assertStringNotContainsString('other@example.test', $list->getContent());
        $this->assertSame($mine->uuid, $list->json('data.0.id'));

        $show = $this->getJson("/api/overrides/{$mine->uuid}")->assertOk();
        $this->assertSame(self::OVERRIDE_FIELDS, $this->keys($show->json('data')));
        $this->assertNoForbiddenWords($show->getContent());

        $edit = $this->putJson("/api/overrides/{$mine->uuid}", ['operator_name' => 'New Name', 'reason' => 'A new reason that is long enough.'])->assertOk();
        $this->assertSame(self::OVERRIDE_FIELDS, $this->keys($edit->json('data')));
        $this->assertNoForbiddenWords($edit->getContent());
    }

    public function test_write_endpoints_return_only_a_public_id(): void
    {
        $override = $this->postJson('/api/overrides', [
            'operator_name' => 'Pat Rivera',
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'scenario' => 'overload',
            'tick' => 40,
        ])->assertCreated();
        $this->assertSame(['id', 'recorded_at'], $this->keys($override->json()));
        $this->assertSame(OverrideLog::query()->value('uuid'), $override->json('id'));

        $feedback = $this->postJson('/api/ai-feedback', [
            'step' => 'diagnosis', 'rating' => 'helpful', 'source' => 'ai', 'scenario' => 'overload', 'tick' => 40,
        ])->assertCreated();
        $this->assertSame(['id'], $this->keys($feedback->json()));
        $this->assertSame(AiFeedback::query()->value('uuid'), $feedback->json('id'));
    }

    public function test_the_review_page_shows_a_short_public_id_and_no_owner_details(): void
    {
        config(['prism.require_login' => true]);
        $owner = User::factory()->create(['email' => 'owner-private@example.test', 'name' => 'Owner Private']);
        $log = $this->entry($owner);

        $page = $this->actingAs($this->adminUser())->get('/overrides')->assertOk();

        $page->assertSee(substr($log->uuid, -8));
        $page->assertSee('Pat Rivera');
        $page->assertDontSee('owner-private@example.test', false);
        $page->assertDontSee('Owner Private');
        $page->assertDontSee('torque_nm', false);
    }

    public function test_another_users_entry_looks_the_same_as_one_that_does_not_exist(): void
    {
        config(['prism.require_login' => true]);
        $me = User::factory()->create();
        $theirs = $this->entry(User::factory()->create());
        $this->actingAs($me);

        $real = $this->getJson("/api/overrides/{$theirs->uuid}");
        $missing = $this->getJson('/api/overrides/'.Str::uuid());

        $real->assertNotFound();
        $this->assertSame($missing->getContent(), $real->getContent());
        $this->putJson("/api/overrides/{$theirs->uuid}", ['operator_name' => 'New Name', 'reason' => 'A new reason that is long enough.'])->assertNotFound();
        $this->deleteJson("/api/overrides/{$theirs->uuid}")->assertNotFound();
        $this->assertNull($theirs->fresh()->edited_at);
    }

    public function test_a_list_instead_of_an_email_is_a_validation_error_not_a_server_error(): void
    {
        config(['prism.require_login' => true]);

        $this->postJson('/login', ['email' => ['a@example.test'], 'password' => 'whatever-1234'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/set-password', ['token' => 'x', 'email' => ['a@example.test'], 'password' => 'Long-Enough-Pass-1!', 'password_confirmation' => 'Long-Enough-Pass-1!'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->get('/set-password/sometoken?email[]=a@example.test')->assertStatus(410);

        $this->actingAs($this->adminUser())
            ->postJson('/admin/invites', ['email' => ['a@example.test']])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
