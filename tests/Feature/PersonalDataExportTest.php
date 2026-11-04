<?php

namespace Tests\Feature;

use App\Models\OverrideLog;
use App\Models\User;
use App\Support\LogId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * The data export (GDPR access and portability): everything PRISM holds
 * about the account, and nothing about anyone else or internal to the app.
 */
class PersonalDataExportTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $other;

    private string $logFolder;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true]);
        $this->me = User::factory()->create(['email' => 'me@example.test', 'name' => 'Contest Judge', 'password' => $this->fixturePassword('me')]);
        $this->other = User::factory()->create(['email' => 'other-person@example.test', 'name' => 'Other Person']);
        $this->logFolder = storage_path('framework/test-export-logs');
        File::ensureDirectoryExists($this->logFolder);
        config(['prism.log_folder' => $this->logFolder]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logFolder);
        parent::tearDown();
    }

    private function entry(User $owner, string $reason): OverrideLog
    {
        return OverrideLog::create([
            'user_id' => $owner->id,
            'reason' => $reason,
            'machine' => 'Milling Machine M-01',
            'scenario' => 'overload',
            'tick' => 59,
            'risk_score' => 90,
            'action_shown' => 'Stop the machine and inspect it now.',
            'reading' => ['torque_nm' => 50],
        ]);
    }

    /** @return array<string, mixed> */
    private function download(): array
    {
        $response = $this->actingAs($this->me)->post('/account/export', ['password' => $this->fixturePassword('me')])->assertOk();
        $this->assertStringStartsWith('attachment; filename="prism-data-export-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        return (array) json_decode((string) $response->getContent(), true);
    }

    /** @return list<string> Every key at every depth. */
    private static function keys(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value)) {
                $keys = [...$keys, ...self::keys($value)];
            }
        }

        return $keys;
    }

    public function test_the_export_holds_the_profile_and_every_entry_the_account_wrote(): void
    {
        $mine = $this->entry($this->me, 'My reason, long enough to save.');
        $mine->update(['reason' => 'My edited reason, long enough.', 'original_reason' => 'My reason, long enough to save.', 'edited_at' => now()]);
        $deleted = $this->entry($this->me, 'A reason I deleted myself later.');
        $deleted->delete();
        Password::broker('invites')->createToken($this->me);

        $data = $this->download();

        $this->assertSame('me@example.test', $data['profile']['email']);
        $this->assertSame('user', $data['profile']['role']);
        $this->assertCount(2, $data['override_entries']);
        $this->assertSame($mine->uuid, $data['override_entries'][0]['id']);
        $this->assertSame('My edited reason, long enough.', $data['override_entries'][0]['reason']);
        $this->assertSame('My reason, long enough to save.', $data['override_entries'][0]['original_reason']);
        $this->assertNotNull($data['override_entries'][1]['deleted_by_you_at']);
        $this->assertNotNull($data['activity']['pending_invite_link']['created_at']);
        $this->assertCount(3, $data['connected_services']);
    }

    public function test_the_export_leaves_out_other_people_and_internal_fields(): void
    {
        $this->entry($this->me, 'My reason, long enough to save.');
        $this->entry($this->other, 'Their reason, which is private.');
        $this->me->forceFill(['failed_login_ips' => ['198.51.100.77'], 'failed_login_attempts' => 1, 'unlock_token_hash' => hash('sha256', 'secret')])->save();

        $data = $this->download();
        $json = (string) json_encode($data);

        $this->assertStringNotContainsString('other-person@example.test', $json);
        $this->assertStringNotContainsString('Other Person', $json);
        $this->assertStringNotContainsString('Their reason', $json);
        // A wrong password may come from someone else, so its address is counted, not listed.
        $this->assertStringNotContainsString('198.51.100.77', $json);
        $this->assertSame(1, $data['profile']['wrong_password_ip_addresses_on_record']);

        $keys = self::keys($data);
        foreach (['password', 'remember_token', 'unlock_token_hash', 'user_id', 'token'] as $internal) {
            $this->assertNotContains($internal, $keys);
        }
        $this->assertArrayNotHasKey('id', $data['profile']);
        $this->assertStringNotContainsString((string) $this->me->getAuthPassword(), $json);
    }

    public function test_log_lines_keep_own_request_details_and_hide_other_peoples_details(): void
    {
        $mine = $this->entry($this->me, 'My reason, long enough to save.');
        $theirs = $this->entry($this->other, 'Their reason, which is private.');
        File::put($this->logFolder.'/laravel.log', implode("\n", [
            '[2026-11-03 18:00:00] production.NOTICE: Session IP address changed {"user_id":'.$this->me->id.',"ip":"203.0.113.5"} ',
            '[2026-11-03 18:01:00] production.WARNING: Refused a request for another user\'s resource {"user_id":'.$this->me->id.',"ip":"203.0.113.5","method":"GET","path":"api/overrides/'.$theirs->uuid.'","resource_id":"'.$theirs->uuid.'"} ',
            '[2026-11-03 18:02:00] production.NOTICE: My own entry {"user_id":'.$this->me->id.',"path":"api/overrides/'.$mine->uuid.'"} ',
            '[2026-11-03 18:03:00] production.NOTICE: Failed sign-in {"ip":"192.0.2.66","email_hash":"'.LogId::email('me@example.test').'"} ',
            '[2026-11-03 18:04:00] production.NOTICE: Session IP address changed {"user_id":'.$this->other->id.',"ip":"192.0.2.99"} []',
            '[2026-11-03 18:05:00] production.NOTICE: Failed sign-in {"ip":"192.0.2.98","email_hash":"'.LogId::email('other-person@example.test').'"} []',
            '',
        ]));

        $lines = $this->download()['activity']['log_lines'];
        $json = (string) json_encode($lines);

        $this->assertCount(4, $lines);
        $this->assertSame('203.0.113.5', $lines[0]['ip']);
        $this->assertSame('api/overrides/{id}', $lines[1]['path']);
        $this->assertArrayNotHasKey('resource_id', $lines[1]);
        $this->assertSame('api/overrides/'.$mine->uuid, $lines[2]['path']);
        $this->assertSame(['time' => '2026-11-03 18:03:00', 'level' => 'notice', 'event' => 'Failed sign-in'], $lines[3]);
        foreach (['192.0.2.66', '192.0.2.98', '192.0.2.99', $theirs->uuid] as $notMine) {
            $this->assertStringNotContainsString($notMine, $json);
        }
    }

    public function test_a_wrong_password_or_a_guest_gets_no_file(): void
    {
        $this->post('/account/export', ['password' => $this->fixturePassword('me')])->assertRedirect('/login');

        $this->actingAs($this->me)->from('/account')
            ->post('/account/export', ['password' => 'not the password'])
            ->assertRedirect('/account')
            ->assertSessionHasErrorsIn('export', 'password');
    }

    public function test_the_command_writes_the_same_export_to_a_file(): void
    {
        $this->entry($this->me, 'My reason, long enough to save.');
        $file = $this->logFolder.'/export.json';

        $this->artisan('prism:export-account', ['email' => 'ME@example.test', '--output' => $file])->assertSuccessful();

        $data = json_decode(File::get($file), true);
        $this->assertSame('me@example.test', $data['profile']['email']);
        $this->assertCount(1, $data['override_entries']);
        $this->artisan('prism:export-account', ['email' => 'nobody@example.test'])->assertFailed();
    }

    public function test_the_account_page_offers_the_download(): void
    {
        $this->actingAs($this->me)->get('/account')->assertOk()
            ->assertSee('Download my data (JSON)')
            ->assertSee('action="'.url('/account/export').'"', false);
    }
}
