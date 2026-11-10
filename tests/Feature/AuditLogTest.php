<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthorizeResource;
use App\Models\OverrideLog;
use App\Models\User;
use App\Support\LogId;
use Database\Seeders\AdminSeeder;
use Database\Seeders\JudgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * The security audit trail: one test group per kind of event the entrant
 * asked for, plus the rules every line must follow (fields, no secrets).
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private const AGENT = 'PRISM-test-agent/1.0';

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true, 'prism.admin.email' => 'admin@example.test']);

        // Replace the audit sink with one the test can read.
        config(['logging.channels.audit' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']]);
        Log::forgetChannel('audit');
        Cache::flush();
    }

    /** @return list<LogRecord> */
    private function lines(?string $event = null): array
    {
        $handler = Log::channel('audit')->getLogger()->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $handler);

        return array_values(array_filter(
            $handler->getRecords(),
            fn (LogRecord $record): bool => $event === null || $record->message === $event,
        ));
    }

    private function line(string $event): LogRecord
    {
        $found = $this->lines($event);
        $this->assertNotEmpty($found, "No audit line for {$event}. Lines: ".implode(', ', array_map(fn (LogRecord $r): string => $r->message, $this->lines())));

        return $found[array_key_last($found)];
    }

    private function allText(): string
    {
        return json_encode(array_map(fn (LogRecord $r): array => $r->context, $this->lines()), JSON_THROW_ON_ERROR);
    }

    private function asBrowser(): static
    {
        return $this->withHeaders(['User-Agent' => self::AGENT]);
    }

    private function user(string $email = 'judge@example.test'): User
    {
        return User::factory()->create(['email' => $email, 'password' => $this->fixturePassword('correct')]);
    }

    private function admin(): User
    {
        return User::factory()->create(['email' => 'admin@example.test', 'password' => $this->fixturePassword('admin')]);
    }

    private function entry(User $owner): OverrideLog
    {
        return OverrideLog::create([
            'user_id' => $owner->id,
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'machine' => 'Milling Machine M-01',
            'scenario' => 'overload',
            'tick' => 59,
            'risk_score' => 90,
            'action_shown' => 'Stop the machine and inspect it now.',
            'reading' => ['torque_nm' => 50],
        ]);
    }

    // 1. Login attempts ------------------------------------------------------

    public function test_a_good_sign_in_is_logged_with_time_ip_and_browser(): void
    {
        $user = $this->user();

        $this->asBrowser()->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/login', ['email' => 'judge@example.test', 'password' => $this->fixturePassword('correct')])
            ->assertRedirect();

        $line = $this->line('auth.login.success');
        $this->assertSame('success', $line->context['outcome']);
        $this->assertSame($user->id, $line->context['actor']['user_id']);
        $this->assertSame('203.0.113.9', $line->context['client']['ip']);
        $this->assertSame(self::AGENT, $line->context['client']['user_agent']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $line->context['at']);
        $this->assertSame('/login', $line->context['request']['path']);
        $this->assertSame(LogId::email('judge@example.test'), $line->context['detail']['email_code']);
        $this->assertSame('info', strtolower($line->level->getName()));
    }

    public function test_a_wrong_password_is_logged_as_a_failure(): void
    {
        $user = $this->user();

        $this->asBrowser()->post('/login', ['email' => 'judge@example.test', 'password' => 'wrong-password-123']);

        $line = $this->line('auth.login.failed');
        $this->assertSame('failure', $line->context['outcome']);
        $this->assertSame('wrong_password', $line->context['detail']['reason']);
        $this->assertSame($user->id, $line->context['detail']['target_user_id']);
        $this->assertNull($line->context['actor']['user_id']);
        $this->assertSame(self::AGENT, $line->context['client']['user_agent']);
        $this->assertSame('warning', strtolower($line->level->getName()));
    }

    public function test_an_unknown_email_is_logged_with_the_reason_but_not_the_address(): void
    {
        $this->asBrowser()->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password-123']);

        $line = $this->line('auth.login.failed');
        $this->assertSame('unknown_email', $line->context['detail']['reason']);
        $this->assertNull($line->context['detail']['target_user_id']);
        $this->assertStringNotContainsString('nobody@example.test', $this->allText());
    }

    public function test_every_refusal_reason_is_logged(): void
    {
        $locked = $this->user('locked@example.test');
        $locked->forceFill(['locked_at' => now()])->save();
        User::factory()->unverified()->create(['email' => 'new@example.test', 'password' => $this->fixturePassword('x')]);

        $this->post('/login', ['email' => 'locked@example.test', 'password' => $this->fixturePassword('correct')]);
        $this->post('/login', ['email' => 'new@example.test', 'password' => $this->fixturePassword('x')]);

        $reasons = array_map(fn (LogRecord $r): string => $r->context['detail']['reason'], $this->lines('auth.login.failed'));
        $this->assertSame(['account_locked', 'account_unverified'], $reasons);
    }

    public function test_repeated_failures_log_an_address_block_and_an_account_lock(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
                ->post('/login', ['email' => 'judge@example.test', 'password' => 'wrong-password-123']);
        }

        $block = $this->line('auth.address_blocked');
        $this->assertSame('alert', $block->context['outcome']);
        $this->assertSame('198.51.100.7', $block->context['client']['ip']);

        // The tenth wrong password in a row, from other addresses, locks the account.
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.(20 + $i)])
                ->post('/login', ['email' => 'judge@example.test', 'password' => 'wrong-password-123']);
        }
        $this->assertSame('alert', $this->line('auth.account_locked')->context['outcome']);
    }

    public function test_a_sign_out_is_logged_with_the_account(): void
    {
        $user = $this->user();

        $this->actingAs($user)->asBrowser()->post('/logout');

        $this->assertSame($user->id, $this->line('auth.logout')->context['actor']['user_id']);
    }

    public function test_no_password_or_plain_email_reaches_the_audit_log(): void
    {
        $this->user();
        $secret = $this->fixturePassword('correct');

        $this->post('/login', ['email' => 'judge@example.test', 'password' => $secret]);
        $this->post('/login', ['email' => 'judge@example.test', 'password' => 'another-wrong-secret-1']);

        $all = $this->allText();
        $this->assertNotSame('[]', $all);
        $this->assertStringNotContainsString($secret, $all);
        $this->assertStringNotContainsString('another-wrong-secret-1', $all);
        $this->assertStringNotContainsString('judge@example.test', $all);
    }

    // 2. Failed authorization ------------------------------------------------

    public function test_reading_or_changing_someone_elses_entry_is_logged_as_denied(): void
    {
        $owner = $this->user('owner@example.test');
        $intruder = $this->user('intruder@example.test');
        $entry = $this->entry($owner);

        // The route's own gate (delete) and the controller's Gate (view, update).
        // The policy answers 404, so a stranger cannot tell the entry exists.
        $this->actingAs($intruder)->asBrowser()->deleteJson("/api/overrides/{$entry->uuid}")->assertNotFound();
        $this->actingAs($intruder)->getJson("/api/overrides/{$entry->uuid}")->assertNotFound();
        $this->actingAs($intruder)->putJson("/api/overrides/{$entry->uuid}", ['reason' => 'A new reason for this entry.'])->assertNotFound();

        $denied = $this->lines('authz.denied');
        $this->assertCount(3, $denied, 'One line per refused request, none twice.');
        foreach ($denied as $line) {
            $this->assertSame('denied', $line->context['outcome']);
            $this->assertSame($intruder->id, $line->context['actor']['user_id']);
            $this->assertStringContainsString($entry->uuid, $line->context['request']['path']);
        }
        $this->assertSame($entry->uuid, $denied[0]->context['detail']['resource_id'], 'The route gate adds the resource.');
        $this->assertSame(404, $denied[1]->context['detail']['status'], 'A refusal the policy answers as 404 is still a refusal.');
        $this->assertSame(self::AGENT, $denied[0]->context['client']['user_agent']);
    }

    public function test_an_unauthenticated_request_to_a_guarded_resource_is_logged(): void
    {
        AuthorizeResource::define('always', fn () => true);
        Route::middleware(['web', 'authorize.resource:always'])->get('/__guarded', fn () => 'ok');

        $this->get('/__guarded');

        $this->assertSame('failure', $this->line('authz.unauthenticated')->context['outcome']);
    }

    public function test_a_non_admin_on_an_admin_page_is_logged_once_and_the_admin_action_is_not(): void
    {
        $this->actingAs($this->user())->get('/admin/invites')->assertForbidden();

        $this->assertCount(1, $this->lines('authz.denied'));
        $this->assertSame([], $this->lines('admin.action'));
    }

    // 3. Account changes -----------------------------------------------------

    public function test_a_new_account_a_new_password_and_a_new_email_are_each_logged(): void
    {
        $user = $this->user('first@example.test');
        $this->assertSame($user->id, $this->line('account.created')->context['detail']['target_user_id']);

        $user->forceFill(['password' => $this->fixturePassword('changed')])->save();
        $this->assertSame($user->id, $this->line('account.password_changed')->context['detail']['target_user_id']);

        $user->forceFill(['email' => 'second@example.test'])->save();
        $email = $this->line('account.email_changed');
        $this->assertSame(LogId::email('first@example.test'), $email->context['detail']['old_email_code']);
        $this->assertSame(LogId::email('second@example.test'), $email->context['detail']['new_email_code']);

        $all = $this->allText();
        $this->assertStringNotContainsString('first@example.test', $all);
        $this->assertStringNotContainsString('second@example.test', $all);
        $this->assertStringNotContainsString((string) $user->password, $all);
    }

    public function test_a_save_that_changes_neither_password_nor_email_logs_nothing(): void
    {
        $user = $this->user();
        $before = count($this->lines());

        $user->forceFill(['failed_login_attempts' => 3])->save();

        $this->assertCount($before, $this->lines());
    }

    public function test_signing_out_other_browsers_is_logged_but_is_not_a_password_change(): void
    {
        $user = $this->user();

        $this->actingAs($user)->asBrowser()
            ->post('/logout-everywhere', ['password' => $this->fixturePassword('correct')])
            ->assertRedirect('/account');

        $this->assertSame($user->id, $this->line('account.sessions_revoked')->context['detail']['target_user_id']);
        $this->assertSame([], $this->lines('account.password_changed'));
    }

    public function test_a_good_sign_in_is_not_logged_as_a_password_change(): void
    {
        $this->user();

        $this->post('/login', ['email' => 'judge@example.test', 'password' => $this->fixturePassword('correct')]);

        $this->assertSame([], $this->lines('account.password_changed'));
    }

    public function test_the_admin_seeder_logs_when_an_account_becomes_an_admin(): void
    {
        config(['prism.admin.password' => $this->fixturePassword('admin-seed'), 'prism.admin.name' => 'Admin']);

        (new AdminSeeder)->run();
        (new AdminSeeder)->run();

        $roles = $this->lines('account.role_changed');
        $this->assertCount(1, $roles, 'Only the run that grants the role is logged.');
        $this->assertSame('admin', $roles[0]->context['detail']['role']);
        $this->assertSame('console', $roles[0]->context['source']);
        $this->assertNull($roles[0]->context['request']);
    }

    public function test_the_seeders_log_a_password_change_only_when_the_password_changes(): void
    {
        config([
            'prism.admin.password' => $this->fixturePassword('admin-seed'), 'prism.admin.name' => 'Admin',
            'prism.judge.email' => 'judge-seed@example.test', 'prism.judge.password' => $this->fixturePassword('judge-seed'), 'prism.judge.name' => 'Judge',
        ]);

        (new AdminSeeder)->run();
        (new JudgeSeeder)->run();
        $this->assertCount(0, $this->lines('account.password_changed'), 'A new account is an account.created line, not a password change.');

        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $hash = $admin->password;

        (new AdminSeeder)->run();
        (new JudgeSeeder)->run();
        $this->assertCount(0, $this->lines('account.password_changed'), 'A deploy that changes nothing writes no password line.');
        $this->assertSame($hash, $admin->fresh()->password);

        config(['prism.admin.password' => $this->fixturePassword('admin-seed-new')]);
        (new AdminSeeder)->run();
        $lines = $this->lines('account.password_changed');
        $this->assertCount(1, $lines);
        $this->assertTrue(Hash::check($this->fixturePassword('admin-seed-new'), (string) $admin->fresh()->password));
    }

    // 4. Admin actions -------------------------------------------------------

    public function test_every_admin_page_request_is_logged_with_the_status(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->asBrowser()->get('/admin/invites')->assertOk();
        $this->actingAs($admin)->get('/overrides')->assertOk();

        $actions = $this->lines('admin.action');
        $this->assertCount(2, $actions);
        $this->assertSame($admin->id, $actions[0]->context['actor']['user_id']);
        $this->assertTrue($actions[0]->context['actor']['admin']);
        $this->assertSame('/admin/invites', $actions[0]->context['request']['path']);
        $this->assertSame(200, $actions[0]->context['detail']['status']);
        $this->assertSame('/overrides', $actions[1]->context['request']['path']);
    }

    public function test_an_admin_invite_and_an_admin_deletion_are_logged(): void
    {
        $admin = $this->admin();
        $target = $this->user('delete-me@example.test');

        $this->actingAs($admin)->post('/admin/invites', ['email' => 'invitee@example.test'])->assertSessionHas('status');
        $invite = $this->line('account.invite_sent');
        $this->assertTrue($invite->context['detail']['new_account']);
        $this->assertSame($admin->id, $invite->context['actor']['user_id']);

        $this->actingAs($admin)->post('/admin/accounts/delete', ['email' => 'delete-me@example.test']);
        $deleted = $this->line('account.deleted');
        $this->assertSame($target->id, $deleted->context['detail']['target_user_id']);
        $this->assertSame($admin->id, $deleted->context['actor']['user_id'], 'The actor is the admin, not the deleted account.');
        $this->assertStringNotContainsString('delete-me@example.test', $this->allText());
    }

    public function test_an_admin_erasing_an_entry_is_logged(): void
    {
        $admin = $this->admin();
        $entry = $this->entry($this->user());

        $this->actingAs($admin)->post("/admin/overrides/{$entry->uuid}/erase");

        $erased = $this->line('data.erased');
        $this->assertSame('override_entry', $erased->context['detail']['scope']);
        $this->assertSame($admin->id, $erased->context['actor']['user_id']);
    }

    // 5. Exports and bulk reads ----------------------------------------------

    public function test_a_personal_data_export_is_logged(): void
    {
        $user = $this->user();

        $this->actingAs($user)->asBrowser()
            ->post('/account/export', ['password' => $this->fixturePassword('correct')])->assertOk();

        $line = $this->line('data.export');
        $this->assertSame($user->id, $line->context['actor']['user_id']);
        $this->assertSame('own_account', $line->context['detail']['scope']);
    }

    public function test_a_wrong_password_on_the_export_logs_no_export(): void
    {
        $this->actingAs($this->user())->post('/account/export', ['password' => 'wrong-password-123']);

        $this->assertSame([], $this->lines('data.export'));
    }

    public function test_list_pages_are_logged_as_bulk_reads_with_a_count(): void
    {
        $admin = $this->admin();
        $owner = $this->user();
        $this->entry($owner);
        $this->entry($owner);

        $this->actingAs($owner)->getJson('/api/overrides')->assertOk();
        $this->flushSession();
        $this->actingAs($admin)->get('/overrides')->assertOk();
        $this->actingAs($admin)->get('/admin/invites')->assertOk();

        $reads = array_map(fn (LogRecord $r): array => [$r->context['detail']['scope'], $r->context['detail']['count']], $this->lines('data.bulk_read'));
        $this->assertSame([['own_override_entries', 2], ['override_log_all_accounts', 2], ['account_list', 2]], $reads);
    }

    public function test_a_judge_demo_visitor_reading_the_sample_rows_is_not_a_bulk_read(): void
    {
        $this->get('/demo');
        $this->get('/overrides')->assertOk();

        $this->assertSame([], $this->lines('data.bulk_read'));
    }

    // 6. API error rate ------------------------------------------------------

    public function test_a_burst_of_api_errors_from_one_address_raises_one_alert(): void
    {
        config(['prism.audit.api_errors_per_ip' => 5, 'prism.audit.api_errors_total' => 1000, 'prism.audit.api_error_window_seconds' => 60]);

        for ($i = 0; $i < 12; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])->getJson('/api/readings?scenario=healthy&tick=0')->assertUnauthorized();
        }

        $alerts = $this->lines('api.error_rate_exceeded');
        $this->assertCount(1, $alerts, 'One alert per window, not one per error.');
        $this->assertSame('alert', $alerts[0]->context['outcome']);
        $this->assertSame('ip_address', $alerts[0]->context['detail']['scope']);
        $this->assertSame(5, $alerts[0]->context['detail']['errors']);
        $this->assertSame(5, $alerts[0]->context['detail']['limit']);
        $this->assertSame('192.0.2.44', $alerts[0]->context['client']['ip']);
    }

    public function test_an_unknown_api_path_counts_as_an_api_error(): void
    {
        config(['prism.audit.api_errors_per_ip' => 3, 'prism.audit.api_errors_total' => 1000]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/does-not-exist')->assertNotFound();
        }

        $this->assertCount(1, $this->lines('api.error_rate_exceeded'));
    }

    public function test_addresses_together_can_raise_the_site_wide_alert(): void
    {
        config(['prism.audit.api_errors_per_ip' => 1000, 'prism.audit.api_errors_total' => 4]);

        foreach (['192.0.2.1', '192.0.2.2', '192.0.2.3', '192.0.2.4'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/api/does-not-exist');
        }

        $this->assertSame('all_addresses', $this->line('api.error_rate_exceeded')->context['detail']['scope']);
    }

    public function test_successful_api_calls_and_other_paths_do_not_count(): void
    {
        config(['prism.audit.api_errors_per_ip' => 2, 'prism.audit.api_errors_total' => 2]);

        for ($i = 0; $i < 5; $i++) {
            $this->get('/not-an-api-path')->assertNotFound();
            $this->flushSession();
            $this->actingAs($this->user("u{$i}@example.test"))->getJson('/api/readings?scenario=healthy&tick=0')->assertOk();
        }

        $this->assertSame([], $this->lines('api.error_rate_exceeded'));
    }

    // The rules every line follows -------------------------------------------

    public function test_the_browser_type_is_cleaned_and_cut_to_255_characters(): void
    {
        $this->withHeaders(['User-Agent' => "evil\tagent \x07".str_repeat('a', 400)])
            ->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password-123']);

        $agent = $this->line('auth.login.failed')->context['client']['user_agent'];
        $this->assertLessThanOrEqual(255, mb_strlen($agent));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $agent);
        $this->assertStringStartsWith('evil agent', $agent);
    }

    public function test_a_missing_browser_type_is_logged_as_null(): void
    {
        $this->withHeaders(['User-Agent' => ''])->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password-123']);

        $this->assertNull($this->line('auth.login.failed')->context['client']['user_agent']);
    }

    public function test_the_query_string_never_reaches_the_audit_log(): void
    {
        config(['prism.audit.api_errors_per_ip' => 1]);

        $this->getJson('/api/does-not-exist?token=secret-value-123');

        $this->assertNotEmpty($this->lines('api.error_rate_exceeded'));
        $this->assertStringNotContainsString('secret-value-123', $this->allText());
    }

    public function test_a_broken_sink_never_changes_the_answer(): void
    {
        config(['logging.channels.audit' => ['driver' => 'monolog', 'handler' => ThrowingAuditHandler::class, 'level' => 'debug']]);
        Log::forgetChannel('audit');
        $this->user();

        $this->post('/login', ['email' => 'judge@example.test', 'password' => $this->fixturePassword('correct')])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_the_audit_channel_is_info_level_whatever_log_level_is_set(): void
    {
        $config = require base_path('config/logging.php');

        $this->assertSame('info', $config['channels']['audit_stderr']['level']);
        $this->assertSame('info', $config['channels']['audit_file']['level']);
        $this->assertTrue($config['channels']['audit']['ignore_exceptions']);
    }

    public function test_the_audit_file_is_outside_the_files_an_account_deletion_edits(): void
    {
        $config = require base_path('config/logging.php');
        $path = (string) $config['channels']['audit_file']['path'];

        // PersonalData::scrubLogFiles edits storage/logs/*.log, one folder deep.
        $this->assertSame(storage_path('logs/audit'), dirname($path));
        $this->assertNotSame(storage_path('logs'), dirname($path));
    }
}

/** A sink that always fails, to prove a logging fault never reaches a visitor. */
class ThrowingAuditHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        throw new \RuntimeException('sink down');
    }
}
