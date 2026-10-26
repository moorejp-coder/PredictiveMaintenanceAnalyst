<?php

namespace Tests\Feature;

use App\Models\AiFeedback;
use App\Models\OverrideLog;
use App\Support\AbuseGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Abuse patterns: attack-looking input and a filled honeypot are rejected and
 * logged. The rate limits are covered in RateLimitTest.
 */
class AbuseGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function attacks(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>', 'script_tag'],
            'script tag with spaces' => ['< script src=x>', 'script_tag'],
            'iframe' => ['<iframe src="x"></iframe>', 'script_tag'],
            'javascript url' => ['JavaScript:alert(1)', 'javascript_url'],
            'event handler' => ['<img src=x onerror=alert(1)>', 'event_handler'],
            'entity encoded script' => ['&lt;script&gt;alert(1)&lt;/script&gt;', 'script_tag'],
            'union select' => ["x' UNION SELECT password FROM users", 'sql_union'],
            'union select with comment' => ['1 UNION/**/SELECT 1,2', 'sql_union'],
            'stacked drop' => ["x'; DROP TABLE users;--", 'sql_stacked'],
            'drop table' => ['please drop table users', 'sql_drop_table'],
            'delete from' => ['delete from users where 1', 'sql_delete'],
            'insert into' => ["insert into users (name) values ('x')", 'sql_insert'],
            'quote tautology' => ["' OR '1'='1", 'sql_tautology'],
            'number tautology' => ['1 OR 1=1', 'sql_numeric_tautology'],
            'quote comment' => ["admin'--", 'sql_quote_comment'],
            'sleep' => ['1 AND sleep(5)', 'sql_time_delay'],
            'schema' => ['select * from information_schema.tables', 'sql_system'],
            'too long' => [str_repeat('a', 1001), 'too_long'],
        ];
    }

    #[DataProvider('attacks')]
    public function test_attack_looking_text_is_detected(string $text, string $pattern): void
    {
        $this->assertSame($pattern, AbuseGuard::check($text));
    }

    /** @return array<string, array{0: string}> */
    public static function honestText(): array
    {
        return [
            ['Finishing the last part of the shift with a supervisor watching.'],
            ['Please select the next part from the bin and drop the table saw guard.'],
            ["Pat O'Brien said the speed and torque are fine."],
            ['Machine "#3" is fine, I will delete from the checklist after the shift.'],
            ['The union rep and the supervisor agreed to select one option.'],
            ['Speed = 1500 and torque = 40, within limits.'],
            [str_repeat('a', 1000)],
        ];
    }

    #[DataProvider('honestText')]
    public function test_ordinary_text_is_not_flagged(string $text): void
    {
        $this->assertNull(AbuseGuard::check($text));
    }

    private function override(array $with = []): array
    {
        return array_merge([
            'operator_name' => 'Pat Rivera',
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'scenario' => 'overload',
            'tick' => 59,
        ], $with);
    }

    public function test_an_attack_in_a_field_is_rejected_with_400_and_logged(): void
    {
        $this->signIn();
        Log::spy();

        $this->postJson('/api/overrides', $this->override(['reason' => "x'; DROP TABLE override_logs;-- long enough"]))
            ->assertStatus(400)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(0, OverrideLog::count());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Rejected abusive input'
            && $context['reason'] === 'sql_stacked'
            && $context['field'] === 'reason'
            && $context['path'] === 'api/overrides'
            && str_contains($context['excerpt'], 'DROP TABLE'));
    }

    public function test_a_very_long_string_is_rejected_and_logged_without_the_full_text(): void
    {
        $this->signIn();
        Log::spy();

        $this->postJson('/api/overrides', $this->override(['reason' => str_repeat('a', 5000)]))->assertStatus(400);

        $this->assertSame(0, OverrideLog::count());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['reason'] === 'too_long'
            && $context['length'] === 5000
            && mb_strlen($context['excerpt']) <= 103);
    }

    public function test_an_attack_in_a_query_string_is_rejected_and_logged(): void
    {
        $this->signIn();
        Log::spy();

        $this->getJson('/api/readings?scenario='.urlencode("x' OR 1=1--").'&tick=1')->assertStatus(400);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['field'] === 'scenario'
            && $context['method'] === 'GET');
    }

    public function test_a_filled_honeypot_is_rejected_and_logged(): void
    {
        $this->signIn();
        Log::spy();

        $this->postJson('/api/overrides', $this->override(['homepage' => 'http://spam.example']))
            ->assertStatus(400);
        $this->postJson('/api/ai-feedback', [
            'step' => 'diagnosis', 'rating' => 'helpful', 'source' => 'ai', 'scenario' => 'overload', 'tick' => 1,
            'homepage' => 'x',
        ])->assertStatus(400);

        $this->assertSame(0, OverrideLog::count());
        $this->assertSame(0, AiFeedback::count());
        Log::shouldHaveReceived('warning')->twice()->withArgs(fn (string $message, array $context) => $context['reason'] === 'honeypot');
    }

    public function test_an_empty_honeypot_is_accepted(): void
    {
        $this->signIn();

        $this->postJson('/api/overrides', $this->override(['homepage' => '']))->assertCreated();
    }

    public function test_a_filled_honeypot_on_the_login_form_is_rejected(): void
    {
        config(['prism.require_login' => true]);
        Log::spy();

        $this->post('/login', ['email' => 'judge@example.test', 'password' => 'whatever', 'homepage' => 'bot'])
            ->assertRedirect()
            ->assertSessionHasErrors('form');

        $this->assertGuest();
    }

    public function test_passwords_are_never_scanned_or_logged(): void
    {
        config(['prism.require_login' => true]);
        Log::spy();

        $this->post('/login', ['email' => 'judge@example.test', 'password' => "x'; DROP TABLE users;--"])
            ->assertSessionHasErrors(['email' => 'The email or password is wrong.']);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_repeated_attacks_hit_the_rate_limit(): void
    {
        $this->signIn();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/overrides', $this->override(['reason' => '<script>alert(1)</script> padding']))->assertStatus(400);
        }

        $this->postJson('/api/overrides', $this->override(['reason' => '<script>alert(1)</script> padding']))->assertStatus(429);
    }

    public function test_every_form_carries_the_hidden_field(): void
    {
        config(['prism.require_login' => true]);

        $this->get('/login')->assertSee('name="homepage"', false)->assertSee('aria-hidden="true"', false);

        $this->signIn();
        $this->get('/')->assertSee('name="homepage"', false);
    }
}
