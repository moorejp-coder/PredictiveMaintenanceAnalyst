<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\JudgePasswordController;
use App\Models\User;
use App\Notifications\JudgeInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Abuse limits on the two account emails PRISM has. Signup is the admin
 * invite (POST /admin/invites), which creates the account. Password reset is
 * the emailed set-password link (/set-password).
 */
class SignupAndResetAbuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true]);
        Notification::fake();
        $this->freezeTime();
    }

    /** @return TestResponse<Response> */
    private function invite(string $email, string $ip = '203.0.113.1'): TestResponse
    {
        $response = $this->actingAs($this->adminUser())
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from('/admin/invites')
            ->post('/admin/invites', ['email' => $email]);
        $this->app['auth']->forgetGuards();

        return $response;
    }

    /** @return array<string, string> */
    private function resetForm(string $email, string $token): array
    {
        return [
            'token' => $token,
            'email' => $email,
            'password' => $this->fixturePassword('a-long-new-passphrase'),
            'password_confirmation' => $this->fixturePassword('a-long-new-passphrase'),
        ];
    }

    public function test_signup_allows_three_new_accounts_per_ip_address_per_hour(): void
    {
        for ($i = 1; $i <= InviteController::MAX_NEW_ACCOUNTS_PER_ADDRESS; $i++) {
            $this->invite('judge'.$i.'@example.test')->assertSessionHasNoErrors();
        }

        $this->invite('judge4@example.test')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '3600');
        $this->assertDatabaseMissing('users', ['email' => 'judge4@example.test']);

        // Another address is not limited.
        $this->invite('judge4@example.test', '203.0.113.2')->assertSessionHasNoErrors();

        // The limit ends after an hour.
        $this->travel(61)->minutes();
        $this->invite('judge5@example.test')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('users', 5);
    }

    public function test_a_new_invite_to_an_existing_account_does_not_count_as_a_new_account(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->invite('judge'.$i.'@example.test');
        }

        $this->travel(2)->minutes();
        $this->invite('judge1@example.test')->assertSessionHasNoErrors();
        Notification::assertSentToTimes(User::where('email', 'judge1@example.test')->sole(), JudgeInvite::class, 2);
    }

    public function test_a_filled_honeypot_creates_no_account_and_sends_no_email(): void
    {
        $this->actingAs($this->adminUser())
            ->post('/admin/invites', ['email' => 'bot@example.test', 'homepage' => 'http://spam.example'])
            ->assertSessionHasErrors('form');

        $this->assertDatabaseMissing('users', ['email' => 'bot@example.test']);
        Notification::assertNothingSent();
    }

    public function test_the_invite_form_carries_the_honeypot_field(): void
    {
        $this->actingAs($this->adminUser())->get('/admin/invites')
            ->assertSee('name="homepage"', false);
    }

    public function test_an_invited_account_cannot_sign_in_until_the_email_link_verifies_it(): void
    {
        $this->invite('judge@example.test')->assertSessionHasNoErrors();
        $user = User::where('email', 'judge@example.test')->sole();
        $this->assertNull($user->email_verified_at);

        // Even with a known password, an unverified account is refused with
        // the same message as a wrong password.
        $user->forceFill(['password' => $this->fixturePassword('known')])->save();
        $this->from('/login')
            ->post('/login', ['email' => 'judge@example.test', 'password' => $this->fixturePassword('known')])
            ->assertSessionHasErrors(['email' => AuthController::INVALID]);
        $this->assertGuest();

        // The emailed link sets the password and verifies the email.
        $token = Password::broker('invites')->createToken($user);
        $this->post('/set-password', $this->resetForm('judge@example.test', $token))->assertRedirect('/login');
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->post('/login', ['email' => 'judge@example.test', 'password' => $this->fixturePassword('a-long-new-passphrase')])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_three_reset_emails_per_email_address_per_hour(): void
    {
        $this->invite('judge@example.test')->assertSessionHasNoErrors();
        for ($i = 2; $i <= InviteController::MAX_LINKS_PER_EMAIL; $i++) {
            // Laravel also refuses a second link within one minute.
            $this->travel(2)->minutes();
            $this->invite('judge@example.test', '198.51.100.'.$i)->assertSessionHasNoErrors();
        }

        // From any address, the fourth email in the hour is refused.
        $this->travel(2)->minutes();
        $this->invite('judge@example.test', '198.51.100.99')
            ->assertStatus(429)
            ->assertHeader('Retry-After', (string) (3600 - 6 * 60));

        $user = User::where('email', 'judge@example.test')->sole();
        Notification::assertSentToTimes($user, JudgeInvite::class, InviteController::MAX_LINKS_PER_EMAIL);

        $this->travel(61)->minutes();
        $this->invite('judge@example.test')->assertSessionHasNoErrors();
    }

    public function test_the_reset_page_gives_one_answer_for_unknown_emails_and_wrong_tokens(): void
    {
        $user = User::factory()->create(['email' => 'judge@example.test']);
        Password::broker('invites')->createToken($user);

        $unknown = $this->get('/set-password/guess?email=nobody%40example.test');
        $wrong = $this->get('/set-password/guess?email=judge%40example.test');

        $unknown->assertStatus(410);
        $wrong->assertStatus(410);
        // The CSRF token and the style nonce change on every request.
        $strip = fn (TestResponse $r): string => (string) preg_replace(['/name="_token" value="[^"]*"/', '/nonce="[^"]*"/'], '', (string) $r->getContent());
        $this->assertSame($strip($unknown), $strip($wrong));

        $message = 'This link is not valid. It was already used or it expired. Ask the contest entrant for a new invite.';
        $this->from('/set-password/x')->post('/set-password', $this->resetForm('nobody@example.test', 'guess'))
            ->assertSessionHasErrors(['email' => $message]);
        $this->from('/set-password/x')->post('/set-password', $this->resetForm('judge@example.test', 'guess'))
            ->assertSessionHasErrors(['email' => $message]);
    }

    public function test_five_wrong_tokens_for_one_email_stop_guessing_for_an_hour_from_any_address(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'judge@example.test']);
        $token = Password::broker('invites')->createToken($user);

        for ($i = 1; $i <= JudgePasswordController::MAX_BAD_TOKENS_PER_EMAIL; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->from('/set-password/x')
                ->post('/set-password', $this->resetForm('judge@example.test', 'guess-'.$i))
                ->assertSessionHasErrors('email');
        }

        // Even the right token is refused now, on the page and the form.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.50'])
            ->post('/set-password', $this->resetForm('judge@example.test', $token))
            ->assertStatus(429)
            ->assertHeader('Retry-After', '3600');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.51'])
            ->get('/set-password/'.$token.'?email=judge%40example.test')
            ->assertStatus(429);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_opening_wrong_links_counts_toward_the_same_limit(): void
    {
        for ($i = 1; $i <= JudgePasswordController::MAX_BAD_TOKENS_PER_EMAIL; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->get('/set-password/guess-'.$i.'?email=nobody%40example.test')
                ->assertStatus(410);
        }

        // An unknown email locks the same way, so the limit reveals nothing.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.60'])
            ->get('/set-password/guess?email=nobody%40example.test')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_a_good_reset_clears_the_wrong_token_count(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'judge@example.test']);
        $token = Password::broker('invites')->createToken($user);

        for ($i = 1; $i < JudgePasswordController::MAX_BAD_TOKENS_PER_EMAIL; $i++) {
            $this->post('/set-password', $this->resetForm('judge@example.test', 'guess-'.$i));
            $this->travel(61)->seconds();
        }

        $this->post('/set-password', $this->resetForm('judge@example.test', $token))->assertRedirect('/login');

        $this->get('/set-password/guess?email=judge%40example.test')->assertStatus(410);
    }
}
