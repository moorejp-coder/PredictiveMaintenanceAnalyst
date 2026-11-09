<?php

namespace Tests\Feature;

use App\Models\OverrideLog;
use App\Models\User;
use App\Notifications\SecurityAlert;
use App\Support\LogId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * The six security alerts (App\Support\Alerts, LoginOrigins) and how an alert
 * reaches email and Slack. Each test uses small limits so a few requests trip
 * the detector. The real defaults sit in config/prism.php.
 */
class SecurityAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.example.test/services/T000/B000/SECRETVALUE';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'prism.require_login' => true,
            'prism.admin.email' => 'admin@example.test',
            'prism.alerts.email' => ['security@example.test'],
            'prism.alerts.slack_webhook' => self::WEBHOOK,
        ]);

        config(['logging.channels.audit' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']]);
        Log::forgetChannel('audit');
        Cache::flush();
        Notification::fake();
        Http::fake(['hooks.example.test/*' => Http::response('ok', 200)]);
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

    private function user(string $email = 'judge@example.test'): User
    {
        return User::factory()->create(['email' => $email, 'password' => $this->fixturePassword('correct')]);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function failLogin(string $email, string $ip): void
    {
        $this->fromIp($ip)->post('/login', ['email' => $email, 'password' => 'wrong-password-123']);
    }

    private function loginFrom(string $email, string $ip, ?string $country = null): void
    {
        $headers = $country === null ? [] : ['CloudFront-Viewer-Country' => $country];
        $this->fromIp($ip)->withHeaders($headers)
            ->post('/login', ['email' => $email, 'password' => $this->fixturePassword('correct')])
            ->assertRedirect();
        // The next sign-in is a new visit.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    private function assertEmailed(string $title): void
    {
        Notification::assertSentOnDemand(
            SecurityAlert::class,
            fn (SecurityAlert $n, array $channels, object $notifiable): bool => $n->title === $title
                && $notifiable->routes['mail'] === ['security@example.test'],
        );
    }

    private function assertSlacked(string $title): void
    {
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === self::WEBHOOK
            && str_contains((string) $r['text'], $title));
    }

    // 1. Failed sign-ins for one account -------------------------------------

    public function test_more_than_ten_failed_sign_ins_for_one_account_in_five_minutes_alert(): void
    {
        $this->user();

        // Ten fail. The tenth locks the account. No alert yet.
        for ($i = 1; $i <= 10; $i++) {
            $this->failLogin('judge@example.test', '198.51.100.'.$i);
        }
        $this->assertSame([], $this->lines('alert.failed_logins'));
        Notification::assertSentOnDemandTimes(SecurityAlert::class, 0);

        // The eleventh is refused by the lock, and still counts.
        $this->failLogin('judge@example.test', '198.51.100.11');

        $alert = $this->lines('alert.failed_logins');
        $this->assertCount(1, $alert);
        $this->assertSame('alert', $alert[0]->context['outcome']);
        $this->assertSame(11, $alert[0]->context['detail']['failed_attempts']);
        $this->assertSame(LogId::email('judge@example.test'), $alert[0]->context['detail']['email_code']);
        $this->assertSame('198.51.100.11', $alert[0]->context['client']['ip']);
        $this->assertEmailed('Many failed sign-ins for one account');
        $this->assertSlacked('Many failed sign-ins for one account');

        // A twelfth does not send a second message while the first one cools down.
        $this->failLogin('judge@example.test', '198.51.100.12');
        $this->assertCount(1, $this->lines('alert.failed_logins'));
        Notification::assertSentOnDemandTimes(SecurityAlert::class, 1);
    }

    public function test_failed_sign_ins_for_an_unknown_email_alert_too(): void
    {
        config(['prism.alerts.failed_logins_per_account' => 2]);

        for ($i = 1; $i <= 3; $i++) {
            $this->failLogin('nobody@example.test', '198.51.100.'.$i);
        }

        $this->assertCount(1, $this->lines('alert.failed_logins'));
    }

    public function test_failures_spread_over_different_accounts_do_not_alert(): void
    {
        config(['prism.alerts.failed_logins_per_account' => 2]);

        for ($i = 1; $i <= 6; $i++) {
            $this->failLogin("person{$i}@example.test", '198.51.100.'.$i);
        }

        $this->assertSame([], $this->lines('alert.failed_logins'));
    }

    public function test_the_failure_count_restarts_after_the_window(): void
    {
        config(['prism.alerts.failed_logins_per_account' => 2]);
        $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'UTC'));

        $this->failLogin('nobody@example.test', '198.51.100.1');
        $this->failLogin('nobody@example.test', '198.51.100.2');
        $this->travel(6)->minutes();
        $this->failLogin('nobody@example.test', '198.51.100.3');

        $this->assertSame([], $this->lines('alert.failed_logins'));
    }

    // 2. One IP address, many accounts ---------------------------------------

    public function test_one_address_naming_more_than_fifty_accounts_alerts(): void
    {
        // The real limit is 50. A small one keeps the test short.
        config(['prism.alerts.accounts_per_ip' => 5]);

        for ($i = 1; $i <= 5; $i++) {
            $this->fromIp('203.0.113.50')->post('/login', ['email' => "a{$i}@example.test", 'password' => 'wrong-password-123']);
        }
        $this->assertSame([], $this->lines('alert.ip_many_accounts'));

        // The sixth is refused by the rate limit (429). It still counts.
        $this->fromIp('203.0.113.50')->post('/login', ['email' => 'a6@example.test', 'password' => 'wrong-password-123']);

        $alert = $this->lines('alert.ip_many_accounts');
        $this->assertCount(1, $alert);
        $this->assertSame('203.0.113.50', $alert[0]->context['client']['ip']);
        $this->assertSame(6, $alert[0]->context['detail']['different_accounts']);
        $this->assertEmailed('One IP address tried many accounts');
        $this->assertStringNotContainsString('a6@example.test', json_encode($alert[0]->context, JSON_THROW_ON_ERROR));
    }

    public function test_the_same_account_many_times_is_not_many_accounts(): void
    {
        config(['prism.alerts.accounts_per_ip' => 2]);

        for ($i = 0; $i < 6; $i++) {
            $this->fromIp('203.0.113.51')->post('/login', ['email' => 'same@example.test', 'password' => 'wrong-password-123']);
        }

        $this->assertSame([], $this->lines('alert.ip_many_accounts'));
    }

    public function test_the_password_and_unlock_pages_count_toward_the_address_total(): void
    {
        config(['prism.alerts.accounts_per_ip' => 2]);

        $this->fromIp('203.0.113.52')->postJson('/set-password/check', ['email' => 'one@example.test', 'token' => 'x']);
        $this->fromIp('203.0.113.52')->postJson('/unlock-account/check', ['email' => 'two@example.test', 'token' => 'x']);
        $this->fromIp('203.0.113.52')->postJson('/set-password/check', ['email' => 'three@example.test', 'token' => 'x']);

        $this->assertCount(1, $this->lines('alert.ip_many_accounts'));
    }

    public function test_a_request_with_a_non_text_email_is_not_a_problem(): void
    {
        $this->fromIp('203.0.113.53')->post('/login', ['email' => ['a'], 'password' => 'wrong-password-123'])
            ->assertSessionHasErrors('email');

        $this->assertSame([], $this->lines('alert.ip_many_accounts'));
    }

    // 3. Bulk data access ----------------------------------------------------

    public function test_a_single_read_of_more_than_one_hundred_records_alerts(): void
    {
        $user = $this->user();
        for ($i = 0; $i < 100; $i++) {
            $this->entryFor($user);
        }

        $this->actingAs($user)->getJson('/api/overrides')->assertOk();
        $this->assertSame([], $this->lines('alert.bulk_read_large'), '100 records is the limit, not over it.');

        $this->entryFor($user);
        $this->actingAs($user)->getJson('/api/overrides')->assertOk();

        $alert = $this->lines('alert.bulk_read_large');
        $this->assertCount(1, $alert);
        $this->assertSame(101, $alert[0]->context['detail']['records']);
        $this->assertSame('own_override_entries', $alert[0]->context['detail']['scope']);
        $this->assertSame($user->id, $alert[0]->context['actor']['user_id']);
        $this->assertEmailed('One request read a large number of records');
        // The existing audit line is still written, with the count.
        $this->assertSame(101, $this->lines('data.bulk_read')[1]->context['detail']['count']);
    }

    public function test_many_bulk_reads_in_a_minute_alert(): void
    {
        config(['prism.alerts.bulk_requests_per_window' => 3]);
        $user = $this->user();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->getJson('/api/overrides')->assertOk();
        }
        $this->assertSame([], $this->lines('alert.bulk_read_rapid'));

        $this->actingAs($user)->getJson('/api/overrides')->assertOk();

        $alert = $this->lines('alert.bulk_read_rapid');
        $this->assertCount(1, $alert);
        $this->assertSame(4, $alert[0]->context['detail']['requests']);
        $this->assertEmailed('Many bulk reads in a short time');
    }

    public function test_exports_count_toward_the_rapid_rule(): void
    {
        config(['prism.alerts.bulk_requests_per_window' => 1]);
        $user = $this->user();

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($user)->post('/account/export', ['password' => $this->fixturePassword('correct')])->assertOk();
        }

        $this->assertCount(1, $this->lines('alert.bulk_read_rapid'));
    }

    // 4. A new country or network --------------------------------------------

    public function test_a_sign_in_from_a_new_country_alerts_after_the_baseline(): void
    {
        config(['prism.alerts.country_header' => 'CloudFront-Viewer-Country']);
        $this->user();

        // The first sign-in only sets the baseline.
        $this->loginFrom('judge@example.test', '203.0.113.9', 'US');
        $this->assertSame([], $this->lines('alert.login_new_country'));
        $this->assertSame([], $this->lines('alert.login_new_network'));

        $this->loginFrom('judge@example.test', '198.51.100.4', 'DE');

        $alert = $this->lines('alert.login_new_country');
        $this->assertCount(1, $alert);
        $this->assertSame('DE', $alert[0]->context['detail']['country']);
        $this->assertSame(['US'], $alert[0]->context['detail']['known_countries']);
        $this->assertSame('198.51.100.4', $alert[0]->context['client']['ip']);
        $this->assertSame([], $this->lines('alert.login_new_network'), 'A new country is one alert, not two.');
        $this->assertEmailed('Sign-in from a new country');
        $this->assertSlacked('Sign-in from a new country');

        // Germany is now known. A repeat does not alert again.
        $this->loginFrom('judge@example.test', '198.51.100.4', 'DE');
        $this->assertCount(1, $this->lines('alert.login_new_country'));
    }

    public function test_a_sign_in_from_a_new_network_in_a_known_country_alerts(): void
    {
        config(['prism.alerts.country_header' => 'CloudFront-Viewer-Country']);
        $this->user();

        $this->loginFrom('judge@example.test', '203.0.113.9', 'US');
        // Same /24 network: not new.
        $this->loginFrom('judge@example.test', '203.0.113.200', 'US');
        $this->assertSame([], $this->lines('alert.login_new_network'));

        $this->loginFrom('judge@example.test', '192.0.2.77', 'US');

        $alert = $this->lines('alert.login_new_network');
        $this->assertCount(1, $alert);
        $this->assertSame('192.0.2.77', $alert[0]->context['client']['ip']);
        $this->assertSame([], $this->lines('alert.login_new_country'));
        $this->assertEmailed('Sign-in from a new network');
    }

    public function test_the_network_check_works_without_a_country_header(): void
    {
        $this->user();

        $this->loginFrom('judge@example.test', '203.0.113.9');
        $this->loginFrom('judge@example.test', '192.0.2.77');

        $this->assertCount(1, $this->lines('alert.login_new_network'));
        $this->assertSame('unknown', $this->lines('alert.login_new_network')[0]->context['detail']['country']);
    }

    public function test_the_network_alert_can_be_switched_off(): void
    {
        config(['prism.alerts.new_network' => false, 'prism.alerts.country_header' => 'CloudFront-Viewer-Country']);
        $this->user();

        $this->loginFrom('judge@example.test', '203.0.113.9', 'US');
        $this->loginFrom('judge@example.test', '192.0.2.77', 'US');
        $this->assertSame([], $this->lines('alert.login_new_network'));

        $this->loginFrom('judge@example.test', '198.51.100.4', 'DE');
        $this->assertCount(1, $this->lines('alert.login_new_country'));
    }

    public function test_a_header_that_is_not_a_country_is_ignored(): void
    {
        config(['prism.alerts.country_header' => 'CloudFront-Viewer-Country', 'prism.alerts.new_network' => false]);
        $this->user();

        $this->loginFrom('judge@example.test', '203.0.113.9', 'US');
        foreach (['XX', 'T1', 'USA', 'u1', '<script>'] as $junk) {
            $this->loginFrom('judge@example.test', '198.51.100.4', $junk);
        }

        $this->assertSame([], $this->lines('alert.login_new_country'));
    }

    public function test_the_origin_table_holds_no_ip_address_and_goes_with_the_account(): void
    {
        config(['prism.alerts.country_header' => 'CloudFront-Viewer-Country']);
        $user = $this->user();
        $this->loginFrom('judge@example.test', '203.0.113.9', 'US');
        $this->loginFrom('judge@example.test', '198.51.100.4', 'DE');

        $stored = json_encode(DB::table('login_origins')->get(), JSON_THROW_ON_ERROR);
        $this->assertSame(2, DB::table('login_origins')->count());
        $this->assertStringNotContainsString('203.0.113', $stored);
        $this->assertStringNotContainsString('198.51.100', $stored);

        $user->delete();
        $this->assertSame(0, DB::table('login_origins')->count());
    }

    public function test_an_account_keeps_at_most_twenty_networks(): void
    {
        config(['prism.alerts.new_network' => false]);
        $this->user();

        for ($i = 1; $i <= 23; $i++) {
            $this->loginFrom('judge@example.test', "10.0.{$i}.1");
        }

        $this->assertSame(20, DB::table('login_origins')->count());
    }

    public function test_the_data_export_lists_origins_without_the_network_code(): void
    {
        config(['prism.alerts.country_header' => 'CloudFront-Viewer-Country']);
        $user = $this->user();
        $this->loginFrom('judge@example.test', '203.0.113.9', 'US');
        $this->loginFrom('judge@example.test', '198.51.100.4', 'DE');

        $export = $this->actingAs($user)->post('/account/export', ['password' => $this->fixturePassword('correct')])->assertOk()->json();

        $origins = $export['activity']['sign_in_origins'];
        $this->assertSame(['US', 'DE'], array_column($origins, 'country'));
        $this->assertNotNull($origins[0]['first_seen_at']);
        $this->assertSame(['country', 'first_seen_at', 'last_seen_at'], array_keys($origins[0]));
        $hashes = DB::table('login_origins')->pluck('network_hash')->all();
        foreach ($hashes as $hash) {
            $this->assertStringNotContainsString($hash, (string) json_encode($export));
        }
    }

    // 5. Password links ------------------------------------------------------

    public function test_three_password_links_to_one_account_in_an_hour_alert(): void
    {
        config(['auth.passwords.invites.throttle' => 0]);
        $admin = User::factory()->create(['email' => 'admin@example.test', 'password' => $this->fixturePassword('admin')]);

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($admin)->post('/admin/invites', ['email' => 'invitee@example.test'])->assertSessionHas('status');
        }
        $this->assertSame([], $this->lines('alert.password_links'));

        $this->actingAs($admin)->post('/admin/invites', ['email' => 'invitee@example.test'])->assertSessionHas('status');

        $alert = $this->lines('alert.password_links');
        $this->assertCount(1, $alert);
        $this->assertSame(3, $alert[0]->context['detail']['links_sent']);
        $this->assertSame(LogId::email('invitee@example.test'), $alert[0]->context['detail']['email_code']);
        $this->assertSame($admin->id, $alert[0]->context['actor']['user_id']);
        $this->assertEmailed('Several password links sent to one account');
    }

    public function test_password_links_to_different_accounts_do_not_alert(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.test', 'password' => $this->fixturePassword('admin')]);

        foreach (['a', 'b', 'c'] as $name) {
            $this->actingAs($admin)->post('/admin/invites', ['email' => "{$name}@example.test"])->assertSessionHas('status');
        }

        $this->assertSame([], $this->lines('alert.password_links'));
    }

    // 6. 401 and 403 above the usual level ------------------------------------

    public function test_refusals_above_the_floor_alert_when_there_is_no_history(): void
    {
        config(['prism.alerts.auth_floor' => 5, 'prism.alerts.auth_per_ip_cap' => 3]);
        $this->travelTo(Carbon::parse('2026-11-09 12:00:30', 'UTC'));

        // One address, however noisy, adds at most 3.
        for ($i = 0; $i < 10; $i++) {
            $this->fromIp('203.0.113.60')->getJson('/api/overrides')->assertUnauthorized();
        }
        $this->assertSame([], $this->lines('alert.auth_failures'));

        for ($i = 0; $i < 10; $i++) {
            $this->fromIp('203.0.113.61')->getJson('/api/overrides')->assertUnauthorized();
        }

        $alert = $this->lines('alert.auth_failures');
        $this->assertCount(1, $alert);
        $this->assertSame(5, $alert[0]->context['detail']['refused_requests']);
        $this->assertSame(0.0, $alert[0]->context['detail']['baseline_per_window']);
        $this->assertEmailed('Refused requests above the usual level');
    }

    public function test_a_normal_level_of_refusals_does_not_alert(): void
    {
        config(['prism.alerts.auth_floor' => 5, 'prism.alerts.auth_per_ip_cap' => 3]);
        $this->travelTo(Carbon::parse('2026-11-09 12:00:30', 'UTC'));

        // The last 24 hours averaged 100 refusals per 5 minutes. Six is nothing.
        $bucket = intdiv(now()->getTimestamp(), 300);
        for ($i = 1; $i <= 288; $i++) {
            Cache::put('alert-authz:'.($bucket - $i).':total', 100, 3600);
        }

        foreach (['203.0.113.60', '203.0.113.61', '203.0.113.62'] as $ip) {
            for ($i = 0; $i < 4; $i++) {
                $this->fromIp($ip)->getJson('/api/overrides')->assertUnauthorized();
            }
        }

        $this->assertSame([], $this->lines('alert.auth_failures'));
    }

    public function test_a_forbidden_page_and_a_policy_that_answers_404_count_too(): void
    {
        config(['prism.alerts.auth_floor' => 2, 'prism.alerts.auth_per_ip_cap' => 5]);
        $owner = $this->user('owner@example.test');
        $intruder = $this->user('intruder@example.test');
        $entry = $this->entryFor($owner);

        $this->fromIp('203.0.113.70')->actingAs($intruder)->get('/admin/invites')->assertForbidden();
        $this->fromIp('203.0.113.71')->actingAs($intruder)->getJson("/api/overrides/{$entry->uuid}")->assertNotFound();

        $this->assertCount(1, $this->lines('alert.auth_failures'));
    }

    public function test_ordinary_answers_are_not_counted(): void
    {
        config(['prism.alerts.auth_floor' => 1]);
        $user = $this->user();

        $this->actingAs($user)->getJson('/api/overrides')->assertOk();
        $this->get('/health');
        $this->get('/no-such-page')->assertNotFound();

        $this->assertSame([], $this->lines('alert.auth_failures'));
    }

    // Delivery ---------------------------------------------------------------

    public function test_with_no_channel_set_the_alert_is_only_an_audit_line(): void
    {
        config(['prism.alerts.email' => [], 'prism.alerts.slack_webhook' => null]);

        config(['prism.alerts.failed_logins_per_account' => 1]);
        $this->failLogin('nobody@example.test', '198.51.100.1');
        $this->failLogin('nobody@example.test', '198.51.100.2');

        $this->assertCount(1, $this->lines('alert.failed_logins'));
        Notification::assertNothingSent();
        Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'hooks.example.test'));
    }

    public function test_a_failing_slack_webhook_never_changes_the_answer_or_leaks_its_address(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response('no', 500)]);
        config(['prism.alerts.failed_logins_per_account' => 1]);

        $this->failLogin('nobody@example.test', '198.51.100.1');
        $response = $this->fromIp('198.51.100.2')->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password-123']);

        $response->assertRedirect();
        $this->assertCount(1, $this->lines('alert.failed_logins'));
        $this->assertEmailed('Many failed sign-ins for one account');
        $text = json_encode(array_map(fn (LogRecord $r): array => $r->context, $this->lines()), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SECRETVALUE', $text);
    }

    public function test_a_webhook_that_is_not_https_is_not_used(): void
    {
        config(['prism.alerts.slack_webhook' => 'http://hooks.example.test/services/plain', 'prism.alerts.failed_logins_per_account' => 1]);

        $this->failLogin('nobody@example.test', '198.51.100.1');
        $this->failLogin('nobody@example.test', '198.51.100.2');

        Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'hooks.example.test'));
    }

    public function test_messages_per_hour_are_capped_and_the_cap_is_logged_once(): void
    {
        config(['prism.alerts.max_notifications_per_hour' => 2, 'prism.alerts.failed_logins_per_account' => 1]);

        foreach (['a', 'b', 'c', 'd'] as $n => $name) {
            $this->failLogin("{$name}@example.test", '198.51.100.'.(10 + 2 * $n));
            $this->failLogin("{$name}@example.test", '198.51.100.'.(11 + 2 * $n));
        }

        $this->assertCount(4, $this->lines('alert.failed_logins'), 'Every alert is still in the audit log.');
        Notification::assertSentOnDemandTimes(SecurityAlert::class, 2);
        $suppressed = $this->lines('alert.notifications_suppressed');
        $this->assertCount(2, $suppressed, 'Once for email and once for Slack.');
        $this->assertEqualsCanonicalizing(['email', 'slack'], array_map(fn (LogRecord $r): string => $r->context['detail']['channel'], $suppressed));
    }

    public function test_a_message_holds_no_email_address_password_or_token(): void
    {
        config(['prism.alerts.failed_logins_per_account' => 1]);
        $this->failLogin('target@example.test', '198.51.100.1');
        $this->failLogin('target@example.test', '198.51.100.2');

        Notification::assertSentOnDemand(SecurityAlert::class, function (SecurityAlert $n): bool {
            $all = $n->title.$n->check.implode("\n", $n->lines);
            $this->assertStringNotContainsString('target@example.test', $all);
            $this->assertStringNotContainsString('wrong-password-123', $all);
            $this->assertStringContainsString('Request IP address: 198.51.100.2', $all);
            $this->assertStringContainsString(LogId::email('target@example.test'), $all);

            return true;
        });
        Http::assertSent(function (HttpRequest $r): bool {
            $this->assertStringNotContainsString('target@example.test', (string) $r['text']);

            return str_contains((string) $r->url(), 'hooks.example.test');
        });
    }

    public function test_the_email_has_a_clear_subject_and_a_what_to_check_line(): void
    {
        $mail = (new SecurityAlert('Many failed sign-ins for one account', 'Look in the audit log.', ['Event: alert.failed_logins']))
            ->toMail(new AnonymousNotifiable);

        $this->assertSame('[PRISM alert] Many failed sign-ins for one account', $mail->subject);
        $this->assertContains('What to check: Look in the audit log.', $mail->introLines);
        $this->assertContains('Event: alert.failed_logins', $mail->introLines);
    }

    private function entryFor(User $owner): OverrideLog
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
}
