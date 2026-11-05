<?php

namespace Tests\Feature;

use App\Models\OverrideLog;
use App\Models\User;
use App\Notifications\AccountDeleted;
use App\Support\EmailBudget;
use App\Support\LogId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * A signed-in user deletes their own account (GDPR right to erasure). Every
 * row, session, counter and log line tied to the account goes, for good, and
 * a confirmation email and page say what was removed.
 */
class AccountSelfDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true]);
        Notification::fake();
        $this->user = User::factory()->create(['email' => 'judge@example.test', 'password' => $this->fixturePassword('judge')]);
        // A folder of its own, so the test never edits the real log files.
        $folder = storage_path('framework/test-logs');
        File::ensureDirectoryExists($folder);
        config(['prism.log_folder' => $folder]);
        $this->logFile = $folder.'/laravel.log';
        File::put($this->logFile, '');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->logFile));
        parent::tearDown();
    }

    private function entry(?User $owner): OverrideLog
    {
        return OverrideLog::create([
            'user_id' => $owner?->id,
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'machine' => 'Milling Machine M-01',
            'scenario' => 'overload',
            'tick' => 59,
            'risk_score' => 90,
            'action_shown' => 'Stop the machine and inspect it now.',
            'reading' => ['torque_nm' => 50],
        ]);
    }

    /** @return array<string, mixed> */
    private function form(array $with = []): array
    {
        return array_merge(['password' => $this->fixturePassword('judge'), 'confirm' => '1'], $with);
    }

    public function test_deleting_removes_every_record_counter_and_log_line_and_sends_a_confirmation(): void
    {
        $id = $this->user->id;
        $mine = $this->entry($this->user);
        $mineDeleted = $this->entry($this->user);
        $mineDeleted->delete();
        $someoneElse = $this->entry(null);
        Password::broker('invites')->createToken($this->user);
        File::put($this->logFile, implode("\n", [
            '[2026-11-03] production.NOTICE: Session IP address changed {"user_id":'.$id.',"ip":"203.0.113.5"}',
            '[2026-11-03] production.NOTICE: Failed sign-in {"ip":"203.0.113.5","email_hash":"'.LogId::email('judge@example.test').'"}',
            '[2026-11-03] production.NOTICE: Session IP address changed {"user_id":'.$id.'0,"ip":"198.51.100.1"}',
            '',
        ]));

        // A counter keyed by the account, written by a real request.
        $this->actingAs($this->user)->getJson('/api/overrides')->assertOk();
        $counter = md5('api-read'.'GET api/overrides|user:'.$id);
        $this->assertSame(1, RateLimiter::attempts($counter));

        $this->post('/account/delete', $this->form())->assertRedirect('/account/deleted');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $id]);
        $this->assertDatabaseMissing('override_logs', ['id' => $mine->id]);
        $this->assertDatabaseMissing('override_logs', ['id' => $mineDeleted->id]);
        $this->assertDatabaseHas('override_logs', ['id' => $someoneElse->id]);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame(0, RateLimiter::attempts($counter));

        // Only the line for another account (user id 10, not 1) is left.
        $left = File::get($this->logFile);
        $this->assertStringNotContainsString('"user_id":'.$id.',', $left);
        $this->assertStringNotContainsString(LogId::email('judge@example.test'), $left);
        $this->assertStringContainsString('"user_id":'.$id.'0,', $left);

        Notification::assertSentOnDemand(AccountDeleted::class, function (AccountDeleted $n, array $channels, AnonymousNotifiable $to): bool {
            return $to->routes['mail'] === 'judge@example.test'
                && $n->removed['overrides'] === 2
                && $n->removed['links'] === 1
                && $n->removed['log_lines'] === 2;
        });

        $this->get('/account/deleted')->assertOk()
            ->assertSee('Your account and all data tied to it are deleted for good.')
            ->assertSee('A confirmation email is on its way');
    }

    public function test_session_files_signed_in_to_the_account_are_deleted(): void
    {
        $folder = storage_path('framework/test-sessions');
        File::ensureDirectoryExists($folder);
        config(['session.driver' => 'file', 'session.files' => $folder, 'session.encrypt' => true]);
        $key = Auth::guard('web')->getName();
        File::put($folder.'/mine', Crypt::encrypt(json_encode([$key => $this->user->id])));
        File::put($folder.'/other', Crypt::encrypt(json_encode([$key => $this->user->id + 1])));
        File::put($folder.'/guest', Crypt::encrypt(json_encode(['_token' => 'x'])));

        try {
            $this->artisan('prism:delete-account', ['email' => 'judge@example.test', '--force' => true])->assertSuccessful();

            $this->assertFileDoesNotExist($folder.'/mine');
            $this->assertFileExists($folder.'/other');
            $this->assertFileExists($folder.'/guest');
        } finally {
            File::deleteDirectory($folder);
        }
    }

    public function test_a_wrong_password_or_no_confirmation_deletes_nothing(): void
    {
        $this->actingAs($this->user);

        $this->from('/account')->post('/account/delete', $this->form(['password' => 'not the password']))
            ->assertRedirect('/account')->assertSessionHasErrorsIn('delete', 'password');
        $this->from('/account')->post('/account/delete', $this->form(['confirm' => null]))
            ->assertRedirect('/account')->assertSessionHasErrorsIn('delete', 'confirm');

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
        Notification::assertNothingSent();
    }

    public function test_the_admin_account_cannot_delete_itself_here(): void
    {
        config(['prism.admin.email' => 'judge@example.test']);

        $this->actingAs($this->user)->from('/account')->post('/account/delete', $this->form())
            ->assertSessionHasErrorsIn('delete', 'password');

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_a_guest_cannot_delete_anything(): void
    {
        $this->post('/account/delete', $this->form())->assertRedirect('/login');
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_the_deletion_stands_when_the_email_cannot_be_sent_and_json_gets_the_receipt(): void
    {
        config(['prism.email_daily_cap' => 1]);
        EmailBudget::record();

        $this->actingAs($this->user)->postJson('/account/delete', $this->form())
            ->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('confirmation_email_sent', false)
            ->assertJsonPath('removed.account', 1);

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        Notification::assertNothingSent();
    }

    public function test_the_account_page_offers_the_deletion(): void
    {
        $this->actingAs($this->user)->get('/account')->assertOk()
            ->assertSee('Delete my account and data')
            ->assertSee('action="'.url('/account/delete').'"', false);
    }
}
