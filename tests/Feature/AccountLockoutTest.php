<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\User;
use App\Notifications\AccountLocked;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The two sign-in locks: 5 failures from one IP address block it for 15
 * minutes, and 10 wrong passwords in a row lock the account until the owner
 * opens the emailed unlock link.
 */
class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true]);
        Notification::fake();
    }

    private function judge(): User
    {
        return User::factory()->create([
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('correct'),
        ]);
    }

    /** @return TestResponse<Response> */
    private function attempt(string $ip, string $email, string $password): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from('/login')
            ->post('/login', ['email' => $email, 'password' => $password]);
    }

    /** Ten wrong passwords, each from its own address, so only the account lock applies. */
    private function lockByGuessing(User $user): void
    {
        for ($i = 1; $i <= AuthController::MAX_FAILURES_PER_ACCOUNT; $i++) {
            $this->attempt('198.51.100.'.$i, $user->email, 'wrong-'.$i)
                ->assertSessionHasErrors(['email' => AuthController::INVALID]);
        }
    }

    private function unlockToken(User $user): string
    {
        $token = '';
        Notification::assertSentTo($user, AccountLocked::class, function (AccountLocked $n) use (&$token): bool {
            $token = $n->token;

            return true;
        });

        return $token;
    }

    public function test_five_failures_from_one_address_block_it_for_fifteen_minutes(): void
    {
        $this->freezeTime();
        $user = $this->judge();

        // Five different emails, so no single account is close to its lock.
        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('203.0.113.7', 'guess'.$i.'@example.test', 'wrong')
                ->assertSessionHasErrors(['email' => AuthController::INVALID]);
        }

        // Past the one-minute route limit, the address block still holds,
        // even with the right password.
        $this->travel(2)->minutes();
        $this->attempt('203.0.113.7', $user->email, $this->fixturePassword('correct'))
            ->assertStatus(429)
            ->assertHeader('Retry-After', (string) (13 * 60))
            ->assertSee(AuthController::ADDRESS_BLOCKED);
        $this->assertGuest();

        // Another address is not blocked.
        $this->attempt('203.0.113.8', $user->email, $this->fixturePassword('correct'))
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->app['auth']->logout();

        // The block ends 15 minutes after the fifth failure.
        $this->travel(14)->minutes();
        $this->attempt('203.0.113.7', $user->email, $this->fixturePassword('correct'))
            ->assertRedirect('/');
    }

    public function test_four_failures_do_not_block_the_address(): void
    {
        $user = $this->judge();

        for ($i = 1; $i <= 4; $i++) {
            $this->attempt('203.0.113.9', $user->email, 'wrong');
        }

        $this->attempt('203.0.113.9', $user->email, $this->fixturePassword('correct'))
            ->assertRedirect('/');
    }

    public function test_ten_wrong_passwords_lock_the_account_for_thirty_minutes_and_email_the_owner(): void
    {
        $this->freezeTime();
        $user = $this->judge();

        $this->lockByGuessing($user);

        $this->assertTrue($user->fresh()->isLocked());
        Notification::assertSentToTimes($user, AccountLocked::class, 1);

        // The right password is refused with the same message as a wrong one.
        $this->travel(29)->minutes();
        $this->attempt('192.0.2.50', $user->email, $this->fixturePassword('correct'))
            ->assertSessionHasErrors(['email' => AuthController::INVALID]);
        $this->assertGuest();

        // The lock ends on its own 30 minutes after it began.
        $this->travel(2)->minutes();
        $this->attempt('192.0.2.51', $user->email, $this->fixturePassword('correct'))->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
        $this->assertNull($user->fresh()->locked_at);
    }

    public function test_after_the_lock_ends_one_wrong_password_does_not_lock_again(): void
    {
        $user = $this->judge();
        $this->lockByGuessing($user);

        $this->travel(31)->minutes();
        $this->attempt('192.0.2.60', $user->email, 'wrong');

        $fresh = $user->fresh();
        $this->assertFalse($fresh->isLocked());
        $this->assertSame(1, $fresh->failed_login_attempts);
        Notification::assertSentToTimes($user, AccountLocked::class, 1);
    }

    public function test_tries_on_a_locked_account_send_no_more_email(): void
    {
        $user = $this->judge();
        $this->lockByGuessing($user);

        $this->attempt('192.0.2.30', $user->email, $this->fixturePassword('correct'));
        $this->attempt('192.0.2.31', $user->email, 'wrong');

        Notification::assertSentToTimes($user, AccountLocked::class, 1);
    }

    public function test_nine_wrong_passwords_do_not_lock_and_a_good_sign_in_resets_the_count(): void
    {
        $user = $this->judge();

        for ($i = 1; $i <= 9; $i++) {
            $this->attempt('198.51.100.'.$i, $user->email, 'wrong');
        }
        $this->assertSame(9, $user->fresh()->failed_login_attempts);
        $this->assertCount(9, $user->fresh()->failed_login_ips);

        $this->attempt('192.0.2.1', $user->email, $this->fixturePassword('correct'))->assertRedirect('/');
        $fresh = $user->fresh();
        $this->assertSame(0, $fresh->failed_login_attempts);
        $this->assertNull($fresh->failed_login_ips);
        $this->assertNull($fresh->first_failed_login_at);
        $this->app['auth']->logout();

        $this->attempt('192.0.2.2', $user->email, 'wrong');
        $this->assertNull($user->fresh()->locked_at);
        Notification::assertNothingSent();
    }

    public function test_unknown_email_wrong_password_and_locked_account_get_the_same_message(): void
    {
        $user = $this->judge();

        $this->attempt('192.0.2.10', 'nobody@example.test', 'wrong')
            ->assertSessionHasErrors(['email' => AuthController::INVALID]);
        $this->attempt('192.0.2.11', $user->email, 'wrong')
            ->assertSessionHasErrors(['email' => AuthController::INVALID]);

        $this->lockByGuessing($user);
        $this->attempt('192.0.2.12', $user->email, 'wrong')
            ->assertSessionHasErrors(['email' => AuthController::INVALID]);
    }

    public function test_the_email_gives_the_message_the_times_the_ip_addresses_and_a_change_password_link(): void
    {
        config(['app.url' => 'https://prism.example.test']);
        $this->travelTo(Carbon::parse('2026-11-02 19:31:00', 'UTC'));
        $user = $this->judge();

        for ($i = 1; $i <= AuthController::MAX_FAILURES_PER_ACCOUNT; $i++) {
            // Two addresses take turns, so each appears once in the email.
            $this->attempt($i % 2 === 0 ? '198.51.100.7' : '203.0.113.9', $user->email, 'wrong');
            $this->travel(1)->minutes();
        }

        $mail = null;
        Notification::assertSentTo($user, AccountLocked::class, function (AccountLocked $n) use ($user, &$mail): bool {
            $mail = $n->toMail($user);

            return true;
        });
        $text = implode("\n", array_merge($mail->introLines, $mail->outroLines));

        $this->assertStringContainsString("Someone tried to log into your account multiple times. If this wasn't you, please change your password.", $text);
        $this->assertStringContainsString('When: 10 wrong passwords in a row, 2 November 2026, between 19:31 and 19:40 UTC.', $text);
        $this->assertStringContainsString('From IP addresses: 203.0.113.9, 198.51.100.7.', $text);
        // The addresses were kept only for this email.
        $this->assertNull($user->fresh()->failed_login_ips);
        $this->assertStringContainsString('It unlocks on its own at 20:10 UTC', $text);
        $this->assertSame('Change your password', $mail->actionText);
        $this->assertStringStartsWith('https://prism.example.test/unlock-account#t=', (string) $mail->actionUrl);
        $this->assertStringEndsWith('&e='.rawurlencode($user->email), (string) $mail->actionUrl);
    }

    public function test_the_link_opens_a_change_password_form_and_an_unlock_button_and_changes_nothing_by_itself(): void
    {
        $user = $this->judge();
        $this->lockByGuessing($user);
        $token = $this->unlockToken($user);
        $this->assertNotSame($token, $user->fresh()->unlock_token_hash);

        $this->get('/unlock-account')
            ->assertOk()
            ->assertSee('Change password</button>', false)
            ->assertSee('Unlock without changing password</button>', false)
            ->assertSee('js/email-link.js')
            ->assertHeader('Referrer-Policy', 'same-origin');
        $this->postJson('/unlock-account/check', ['token' => $token, 'email' => $user->email])
            ->assertOk()
            ->assertExactJson(['valid' => true]);
        $this->assertTrue($user->fresh()->isLocked());
    }

    public function test_the_unlock_button_unlocks_and_keeps_the_password(): void
    {
        $user = $this->judge();
        $this->lockByGuessing($user);
        $token = $this->unlockToken($user);

        $this->post('/unlock-account', ['token' => $token, 'email' => $user->email])
            ->assertRedirect('/login')
            ->assertSessionHas('status');

        $fresh = $user->fresh();
        $this->assertFalse($fresh->isLocked());
        $this->assertSame(0, $fresh->failed_login_attempts);
        $this->assertNull($fresh->unlock_token_hash);

        $this->attempt('192.0.2.20', $user->email, $this->fixturePassword('correct'))->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->app['auth']->logout();

        // The link works once.
        $this->postJson('/unlock-account/check', ['token' => $token, 'email' => $user->email])->assertStatus(410);
    }

    public function test_changing_the_password_from_the_link_unlocks_the_account(): void
    {
        $user = $this->judge();
        $this->lockByGuessing($user);
        $token = $this->unlockToken($user);
        $new = $this->fixturePassword('a-brand-new-long-passphrase');

        $this->post('/unlock-account/password', [
            'token' => $token,
            'email' => $user->email,
            'password' => $new,
            'password_confirmation' => $new,
        ])->assertRedirect('/login')->assertSessionHas('status');

        $this->assertFalse($user->fresh()->isLocked());
        $this->assertNull($user->fresh()->unlock_token_hash);

        $this->attempt('192.0.2.21', $user->email, $this->fixturePassword('correct'))
            ->assertSessionHasErrors(['email' => AuthController::INVALID]);
        $this->attempt('192.0.2.22', $user->email, $new)->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_short_or_mismatched_new_password_is_refused_and_the_account_stays_locked(): void
    {
        $user = $this->judge();
        $this->lockByGuessing($user);
        $token = $this->unlockToken($user);

        $this->from('/unlock-account')
            ->post('/unlock-account/password', ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->from('/unlock-account')
            ->post('/unlock-account/password', ['token' => $token, 'email' => $user->email, 'password' => $this->fixturePassword('one-long-phrase'), 'password_confirmation' => $this->fixturePassword('another-long-phrase')])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->isLocked());
    }

    public function test_the_link_still_changes_the_password_after_the_lock_ends_on_its_own(): void
    {
        $user = $this->judge();
        $this->lockByGuessing($user);
        $token = $this->unlockToken($user);
        $new = $this->fixturePassword('a-brand-new-long-passphrase');

        $this->travel(45)->minutes();
        $this->post('/unlock-account/password', ['token' => $token, 'email' => $user->email, 'password' => $new, 'password_confirmation' => $new])
            ->assertRedirect('/login')
            ->assertSessionHas('status');
    }

    public function test_a_wrong_token_another_email_or_an_expired_link_changes_nothing(): void
    {
        $user = $this->judge();
        $this->lockByGuessing($user);
        $token = $this->unlockToken($user);
        $new = $this->fixturePassword('a-brand-new-long-passphrase');

        $this->postJson('/unlock-account/check', ['token' => 'not-the-token', 'email' => $user->email])->assertStatus(410);
        $this->postJson('/unlock-account/check', ['token' => $token, 'email' => 'nobody@example.test'])->assertStatus(410);
        $this->post('/unlock-account', ['token' => 'not-the-token', 'email' => $user->email])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertTrue($user->fresh()->isLocked());

        $this->travel(61)->minutes();
        $this->postJson('/unlock-account/check', ['token' => $token, 'email' => $user->email])->assertStatus(410);
        $this->post('/unlock-account/password', ['token' => $token, 'email' => $user->email, 'password' => $new, 'password_confirmation' => $new])
            ->assertSessionHasErrors('email');
        $this->attempt('192.0.2.40', $user->email, $new)->assertSessionHasErrors(['email' => AuthController::INVALID]);
    }

    public function test_the_unlock_routes_are_404_while_the_sign_in_is_off(): void
    {
        config(['prism.require_login' => false]);

        $this->get('/unlock-account')->assertNotFound();
        $this->postJson('/unlock-account/check', ['token' => 'abc', 'email' => 'judge@example.test'])->assertNotFound();
        $this->post('/unlock-account', ['token' => 'abc', 'email' => 'judge@example.test'])->assertNotFound();
        $this->post('/unlock-account/password', ['token' => 'abc', 'email' => 'judge@example.test'])->assertNotFound();
    }

    /**
     * Stored values that do not decrypt: a plain list written before the column
     * was encrypted, a list encrypted with another key, and garbage.
     *
     * @return array<string, array{string}>
     */
    public static function unreadableStoredLists(): array
    {
        return [
            'plain list from before encryption' => ['["203.0.113.9"]'],
            'encrypted with another key' => [(new Encrypter(str_repeat('k', 32), 'AES-256-CBC'))->encryptString('["203.0.113.9"]')],
            'garbage' => ['not a payload'],
        ];
    }

    private function storeRawIps(User $user, string $raw, int $attempts): void
    {
        DB::table('users')->where('id', $user->id)->update([
            'failed_login_ips' => $raw,
            'failed_login_attempts' => $attempts,
        ]);
    }

    #[DataProvider('unreadableStoredLists')]
    public function test_a_wrong_password_still_gets_the_normal_answer_when_the_stored_ip_list_cannot_be_read(string $raw): void
    {
        $user = $this->judge();
        $this->storeRawIps($user, $raw, 2);

        $this->attempt('198.51.100.5', $user->email, 'wrong')
            ->assertSessionHasErrors(['email' => AuthController::INVALID]);

        $fresh = $user->fresh();
        $this->assertSame(3, $fresh->failed_login_attempts);
        // The unreadable list is replaced by one encrypted with the current key.
        $this->assertSame(['198.51.100.5'], $fresh->failed_login_ips);
    }

    #[DataProvider('unreadableStoredLists')]
    public function test_a_good_sign_in_clears_the_count_when_the_stored_ip_list_cannot_be_read(string $raw): void
    {
        $user = $this->judge();
        $this->storeRawIps($user, $raw, 3);

        $this->attempt('192.0.2.1', $user->email, $this->fixturePassword('correct'))->assertRedirect('/');

        $fresh = $user->fresh();
        $this->assertSame(0, $fresh->failed_login_attempts);
        $this->assertNull($fresh->failed_login_ips);
    }
}
