<?php

namespace Tests\Feature;

use App\Models\User;
use App\Rules\NotCommonPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The rules for choosing a password on the set-password form: length, the
 * common-password list, the data-breach check, and no complexity rules.
 */
class PasswordRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true]);
    }

    /** @return array<string, string> */
    private function form(string $password): array
    {
        $user = User::factory()->unverified()->create(['email' => 'judge@example.test']);

        return [
            'token' => Password::broker('invites')->createToken($user),
            'email' => 'judge@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }

    public function test_the_common_password_list_ignores_case(): void
    {
        $rule = new NotCommonPassword;

        foreach (['password', 'Password', 'PASSWORD', '12345678', 'unbelievable'] as $weak) {
            $this->assertTrue(Validator::make(['p' => $weak], ['p' => $rule])->fails(), $weak);
        }

        $this->assertFalse(Validator::make(['p' => 'correct horse battery staple'], ['p' => $rule])->fails());
    }

    public function test_a_common_password_is_refused_on_the_form(): void
    {
        $this->post('/set-password', $this->form('Unbelievable'))->assertSessionHasErrors('password');
    }

    public function test_a_password_found_in_a_breach_is_refused(): void
    {
        $password = 'a-long-but-breached-phrase';
        $hash = strtoupper(sha1($password));
        Http::swap(new Factory);
        Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).':4821', 200)]);

        $this->post('/set-password', $this->form($password))->assertSessionHasErrors('password');

        // Only the first five characters of the hash leave the server.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/range/'.substr($hash, 0, 5)));
    }

    public function test_a_long_plain_phrase_is_accepted_with_no_symbol_or_digit_rule(): void
    {
        $this->post('/set-password', $this->form('correct horse battery staple'))->assertRedirect('/login');
        $this->post('/login', ['email' => 'judge@example.test', 'password' => 'correct horse battery staple'])->assertRedirect('/');
    }

    public function test_a_breach_service_outage_does_not_block_a_good_password(): void
    {
        Http::swap(new Factory);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 500)]);

        $this->post('/set-password', $this->form('correct horse battery staple'))->assertRedirect('/login');
    }

    public function test_a_password_shorter_than_twelve_characters_is_refused(): void
    {
        $this->post('/set-password', $this->form('elevenchars'))->assertSessionHasErrors('password');
    }

    public function test_a_refused_password_is_never_shown_flashed_or_logged(): void
    {
        Log::spy();
        $password = 'Unbelievable';

        $response = $this->from('/set-password/x')->post('/set-password', $this->form($password));

        $response->assertSessionHasErrors('password');
        $response->assertSessionMissing('_old_input.password');
        $response->assertSessionMissing('_old_input.password_confirmation');
        $this->assertStringNotContainsString($password, json_encode(session('errors')->getBag('default')->all()));
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_no_script_writes_to_the_browser_console(): void
    {
        foreach (glob(public_path('js/*.js')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/console\.(log|debug|info|warn|error)\s*\(/', file_get_contents($file), basename($file));
        }
    }
}
