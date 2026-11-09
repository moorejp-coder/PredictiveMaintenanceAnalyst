<?php

namespace Tests\Feature;

use App\Logging\RedactLogs;
use App\Logging\Redactor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use RuntimeException;
use Tests\TestCase;

/**
 * Logs never hold a password, a hash, a key, a token, a session value, a card
 * number or a full Social Security number. The values are built here at run
 * time so no secret-shaped string sits in the source.
 */
class LogRedactionTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private string $jsonFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'prism-log-') ?: '';
        $this->jsonFile = tempnam(sys_get_temp_dir(), 'prism-json-') ?: '';

        config([
            'logging.channels.redact_test' => [
                'driver' => 'single',
                'path' => $this->file,
                'level' => 'debug',
                'permission' => 0640,
                'tap' => [RedactLogs::class],
            ],
            'logging.channels.redact_json' => [
                'driver' => 'monolog',
                'handler' => StreamHandler::class,
                'handler_with' => ['stream' => $this->jsonFile],
                'formatter' => JsonFormatter::class,
                'level' => 'debug',
                'tap' => [RedactLogs::class],
            ],
        ]);
        Log::forgetChannel('redact_test');
        Log::forgetChannel('redact_json');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink($this->jsonFile);

        parent::tearDown();
    }

    private function stripeKey(): string
    {
        return 'sk_live_'.'ABCDEFGH'.'ijklmnop'.'12345678';
    }

    private function hash(): string
    {
        return '$2y$12$'.str_repeat('a1B2c3', 8).'abcde';
    }

    public function test_a_password_hash_is_replaced(): void
    {
        $this->assertSame('x '.Redactor::HASH.' y', Redactor::text('x '.$this->hash().' y'));
    }

    public function test_an_api_key_keeps_its_prefix_and_last_four(): void
    {
        $this->assertSame('key sk_live_****5678 end', Redactor::text('key '.$this->stripeKey().' end'));

        $anthropic = 'sk-ant-'.'api03-'.'abcdefghijklmnopqrstuvwx9876';
        $out = Redactor::text('failed with '.$anthropic);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwx', $out);
        $this->assertStringContainsString('9876', $out);
    }

    public function test_bearer_tokens_and_auth_headers_are_replaced(): void
    {
        $token = 'abcdef0123456789abcdef0123456789';

        $this->assertStringNotContainsString($token, Redactor::text('Bearer '.$token));
        $this->assertStringNotContainsString($token, Redactor::text('Authorization: Basic '.$token));
        $this->assertStringNotContainsString($token, Redactor::text('Cookie: laravel_session='.$token.'; XSRF-TOKEN='.$token));
        $this->assertStringNotContainsString($token, Redactor::text('Set-Cookie: session='.$token.'; HttpOnly'));
        $this->assertStringNotContainsString($token, Redactor::text(json_encode(['headers' => ['Authorization' => 'Bearer '.$token]], JSON_THROW_ON_ERROR)));
    }

    public function test_session_and_cookie_values_are_never_shown_in_part(): void
    {
        $session = 'Zk3JHqPz0000AAAAbbbbCCCCddddEEEEffff9999';

        $out = Redactor::text('session_id='.$session);
        $this->assertStringNotContainsString('9999', $out);
        $this->assertStringContainsString(Redactor::REDACTED, $out);

        $clean = Redactor::context(['session_id' => $session, 'laravel_session' => $session]);
        $this->assertSame([Redactor::REDACTED, Redactor::REDACTED], array_values($clean));
    }

    public function test_key_value_pairs_with_a_secret_name_are_replaced(): void
    {
        $out = Redactor::text('password=Hunter2Hunter2 and {"password":"Hunter2Hunter2","current_password":"Hunter2Hunter2"} and password_confirmation: Hunter2Hunter2');

        $this->assertStringNotContainsString('Hunter2', $out);
    }

    public function test_counters_with_a_token_in_the_name_are_left_alone(): void
    {
        $this->assertSame('input_tokens=1234 output_tokens=88 token_count=5', Redactor::text('input_tokens=1234 output_tokens=88 token_count=5'));
        $this->assertSame(['input_tokens' => 1234, 'output_tokens' => 88], Redactor::context(['input_tokens' => 1234, 'output_tokens' => 88]));
    }

    public function test_context_values_under_secret_keys_are_masked(): void
    {
        $clean = Redactor::context([
            'user_id' => 7,
            'password' => 'Hunter2Hunter2',
            'api_key' => $this->stripeKey(),
            'nested' => ['access_token' => $this->stripeKey(), 'note' => 'fine'],
            'xsrfToken' => 'abcdef0123456789abcdef',
        ]);

        $this->assertSame(7, $clean['user_id']);
        $this->assertSame(Redactor::REDACTED, $clean['password']);
        $this->assertSame('sk_live_****5678', $clean['api_key']);
        $this->assertSame('sk_live_****5678', $clean['nested']['access_token']);
        $this->assertSame('fine', $clean['nested']['note']);
        $this->assertSame(Redactor::REDACTED, $clean['xsrfToken']);
    }

    public function test_card_numbers_keep_only_the_last_four(): void
    {
        $this->assertSame('card ****-****-****-1111 ok', Redactor::text('card 4111 1111 1111 1111 ok'));
        $this->assertSame('card ****-****-****-1111 ok', Redactor::text('card 4111-1111-1111-1111 ok'));
        $this->assertSame('****-****-****-1111', Redactor::text('4111111111111111'));
    }

    public function test_a_long_number_that_is_not_a_card_is_left_alone(): void
    {
        // Fails the Luhn check, so it is an id or a timestamp, not a card.
        $this->assertSame('id 4111111111111112', Redactor::text('id 4111111111111112'));
        $this->assertSame('at 2026-11-09T15:30:00.123456Z', Redactor::text('at 2026-11-09T15:30:00.123456Z'));
    }

    public function test_social_security_numbers_keep_only_the_last_four(): void
    {
        $this->assertSame('ssn ***-**-6789 ok', Redactor::text('ssn 123-45-6789 ok'));
        $this->assertSame('call 202-555-0143', Redactor::text('call 202-555-0143'));
        $this->assertSame(Redactor::REDACTED, Redactor::context(['ssn' => '123456789'])['ssn']);
    }

    public function test_urls_with_a_password_or_token_are_masked(): void
    {
        $this->assertSame('smtp://mailer:****@mail.example.test:587', Redactor::text('smtp://mailer:Hunter2Hunter2@mail.example.test:587'));

        $out = Redactor::text('GET /x?token='.$this->stripeKey().'&page=2');
        $this->assertStringNotContainsString('ABCDEFGH', $out);
        $this->assertStringContainsString('page=2', $out);
    }

    public function test_sql_with_inlined_values_in_an_error_is_replaced(): void
    {
        $message = 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: users.email (Connection: sqlite, SQL: insert into "users" ("email", "password") values (judge@example.test, '.$this->hash().'))';

        $out = Redactor::text($message.' at /app/Models/User.php:50');

        $this->assertStringNotContainsString('judge@example.test', $out);
        $this->assertStringNotContainsString($this->hash(), $out);
        $this->assertStringContainsString('UNIQUE constraint failed', $out);
        $this->assertStringContainsString('at /app/Models/User.php:50', $out);
    }

    public function test_the_log_file_never_holds_the_secret_and_is_not_world_readable(): void
    {
        $key = $this->stripeKey();

        Log::channel('redact_test')->warning('Call failed with '.$key, [
            'password' => 'Hunter2Hunter2',
            'card' => '4111 1111 1111 1111',
            'headers' => ['Cookie' => 'laravel_session=abcdef0123456789abcdef'],
            'exception' => new RuntimeException('SQL: select * from users where password = '.$this->hash().')'),
        ]);

        $text = (string) file_get_contents($this->file);

        foreach ([$key, 'Hunter2Hunter2', '4111 1111 1111 1111', 'abcdef0123456789abcdef', $this->hash()] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertStringContainsString('sk_live_****5678', $text);
        $this->assertStringContainsString('****-****-****-1111', $text);

        clearstatcache(true, $this->file);
        $this->assertSame(0, fileperms($this->file) & 0007, 'The log file must give others no access.');
    }

    public function test_the_json_audit_format_is_cleaned_too(): void
    {
        Log::channel('redact_json')->warning('auth.test', [
            'detail' => ['token' => $this->stripeKey(), 'password' => 'Hunter2Hunter2', 'note' => 'ssn 123-45-6789'],
            'exception' => new RuntimeException('Bearer abcdef0123456789abcdef0123'),
        ]);

        $text = (string) file_get_contents($this->jsonFile);

        foreach ([$this->stripeKey(), 'Hunter2Hunter2', '123-45-6789', 'abcdef0123456789abcdef0123'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertIsArray(json_decode(trim($text), true), 'The line is still valid JSON.');
    }

    public function test_every_log_channel_carries_the_redaction(): void
    {
        // "emergency" is Laravel's last-resort file, built by the framework
        // with no tap hook. It is written only when another channel fails.
        $skip = ['stack', 'audit', 'null', 'emergency', 'redact_test', 'redact_json'];

        foreach ((array) config('logging.channels') as $name => $channel) {
            if (in_array($name, $skip, true)) {
                continue;
            }

            $this->assertContains(RedactLogs::class, $channel['tap'] ?? [], "Channel {$name} has no redaction.");
        }
    }

    public function test_file_channels_create_files_that_others_cannot_read(): void
    {
        foreach (['single', 'daily', 'monthly', 'audit_file'] as $name) {
            $this->assertSame(0640, config("logging.channels.{$name}.permission"), "Channel {$name} file permission.");
        }
    }

    public function test_no_log_call_passes_an_exception_message_through(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(app_path()) as $path) {
            foreach (file($path) ?: [] as $number => $line) {
                if (preg_match('/Log::|logger\(/', $line) === 1 && str_contains($line, 'getMessage()')) {
                    $offenders[] = str_replace(base_path().'/', '', $path).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Log the exception class, not its message. A message can hold SQL values or a DSN.');
    }

    public function test_a_failed_sign_in_leaves_no_password_in_the_log(): void
    {
        $this->sendAuditAndAppLogsToFiles();

        $user = User::factory()->create(['email' => 'judge@example.test', 'password' => $this->fixturePassword('correct')]);
        $wrong = 'Wrong-'.'Passw0rd-'.'Zed99';

        $this->post('/login', ['email' => $user->email, 'password' => $wrong])->assertStatus(302);

        $text = (string) file_get_contents($this->file).(string) file_get_contents($this->jsonFile);

        $this->assertNotSame('', $text, 'The failed sign-in should be logged.');
        $this->assertStringNotContainsString($wrong, $text);
        $this->assertStringNotContainsString($this->fixturePassword('correct'), $text);
        $this->assertStringNotContainsString('judge@example.test', $text);
    }

    public function test_a_flagged_request_field_is_logged_masked(): void
    {
        $this->sendAuditAndAppLogsToFiles();

        $this->post('/login', [
            'email' => 'a@example.test',
            'password' => 'x',
            'homepage' => 'card 4111 1111 1111 1111 key '.$this->stripeKey(),
        ]);

        $text = (string) file_get_contents($this->file);

        $this->assertStringContainsString('Rejected abusive input', $text);
        $this->assertStringNotContainsString('4111 1111 1111 1111', $text);
        $this->assertStringNotContainsString($this->stripeKey(), $text);
    }

    public function test_the_web_root_does_not_serve_logs(): void
    {
        $this->assertDirectoryDoesNotExist(public_path('storage'));
        $this->assertSame([], glob(public_path('*.log')) ?: []);
        $this->assertSame([], glob(public_path('*.sqlite')) ?: []);

        foreach (['/storage/logs/laravel.log', '/storage/logs/audit/audit.log', '/laravel.log', '/.env'] as $path) {
            $this->get($path)->assertNotFound();
        }

        $conf = (string) file_get_contents(base_path('.platform/nginx/conf.d/elasticbeanstalk/laravel.conf'));
        $this->assertStringContainsString('log|sqlite', $conf);
    }

    private function sendAuditAndAppLogsToFiles(): void
    {
        config([
            'prism.require_login' => true,
            'logging.default' => 'redact_test',
            'logging.channels.audit' => config('logging.channels.redact_json'),
        ]);
        Log::forgetChannel('redact_test');
        Log::forgetChannel('audit');
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}
