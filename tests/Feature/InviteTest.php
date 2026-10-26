<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\JudgeInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * The judge invite flow: the entrant types an email, the judge gets a link,
 * chooses a password, then signs in at /login.
 */
class InviteTest extends TestCase
{
    use RefreshDatabase;

    private function adminPassword(): string
    {
        return $this->fixturePassword('admin');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true, 'prism.invite_admin_password' => $this->adminPassword()]);
    }

    /** @return array<string, string> */
    private function admin(): array
    {
        return ['Authorization' => 'Basic '.base64_encode('entrant:'.$this->adminPassword())];
    }

    private function newToken(User $user): string
    {
        return Password::broker('invites')->createToken($user);
    }

    public function test_the_invite_pages_do_not_exist_while_the_sign_in_is_off(): void
    {
        config(['prism.require_login' => false]);

        $this->withHeaders($this->admin())->get('/admin/invites')->assertNotFound();
        $this->withHeaders($this->admin())->post('/admin/invites', ['email' => 'judge@example.test'])->assertNotFound();
        $this->get('/set-password/anything?email=judge@example.test')->assertNotFound();
        $this->post('/set-password', [])->assertNotFound();
    }

    public function test_the_invite_page_is_404_without_a_password_setting_and_401_without_the_password(): void
    {
        config(['prism.invite_admin_password' => null]);
        $this->withHeaders($this->admin())->get('/admin/invites')->assertNotFound();

        config(['prism.invite_admin_password' => $this->adminPassword()]);
        // withHeaders() above stays on every later request in this test, so
        // clear it before checking that a request with no password gets 401.
        $this->flushHeaders();
        $this->get('/admin/invites')->assertStatus(401);
        $this->get('/admin/invites', ['Authorization' => 'Basic '.base64_encode('entrant:wrong')])->assertStatus(401);
        $this->withHeaders($this->admin())->get('/admin/invites')->assertOk()->assertSee('Invite a judge');
    }

    public function test_the_invite_page_lists_accounts_with_their_status(): void
    {
        User::factory()->create(['email' => 'active@example.test']);
        User::factory()->unverified()->create(['email' => 'waiting@example.test']);

        $this->withHeaders($this->admin())->get('/admin/invites')
            ->assertSee('active@example.test')
            ->assertSee('waiting@example.test')
            ->assertSee('<span class="tag">Active</span>', false)
            ->assertSee('<span class="tag">Invited</span>', false);
    }

    public function test_inviting_creates_an_unusable_account_and_emails_a_link(): void
    {
        Notification::fake();

        $this->withHeaders($this->admin())
            ->post('/admin/invites', ['email' => '  Judge@Example.TEST ', 'name' => 'Pat Judge'])
            ->assertSessionHas('status', 'Invite sent to judge@example.test.');

        $user = User::where('email', 'judge@example.test')->firstOrFail();
        $this->assertSame('Pat Judge', $user->name);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, JudgeInvite::class);

        // Nobody knows the starting password, so the account cannot sign in yet.
        $this->post('/login', ['email' => 'judge@example.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_invite_email_holds_a_link_and_no_password(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'j@example.test']);

        $mail = (new JudgeInvite('tok123'))->toMail($user);

        $this->assertSame('Set your password for the PRISM contest demo', $mail->subject);
        $this->assertStringContainsString('/set-password/tok123?email=j%40example.test', $mail->actionUrl);
        $this->assertStringContainsString('works once and expires in 30 minutes', implode(' ', $mail->outroLines));
    }

    public function test_the_whole_flow_from_invite_to_sign_in(): void
    {
        Notification::fake();
        $this->withHeaders($this->admin())->post('/admin/invites', ['email' => 'judge@example.test']);
        $user = User::where('email', 'judge@example.test')->firstOrFail();

        $token = null;
        Notification::assertSentTo($user, JudgeInvite::class, function (JudgeInvite $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $page = $this->get('/set-password/'.$token.'?email=judge@example.test')
            ->assertOk()
            ->assertSee('Set your password')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));

        $this->post('/set-password', [
            'token' => $token,
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('judge'),
            'password_confirmation' => $this->fixturePassword('judge'),
        ])->assertRedirect('/login');

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->post('/login', ['email' => 'Judge@Example.test', 'password' => $this->fixturePassword('judge')])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_link_works_only_once(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'judge@example.test']);
        $token = $this->newToken($user);
        $form = [
            'token' => $token,
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('judge-first'),
            'password_confirmation' => $this->fixturePassword('judge-first'),
        ];

        $this->post('/set-password', $form)->assertRedirect('/login');

        $this->post('/set-password', array_merge($form, [
            'password' => $this->fixturePassword('judge-second'),
            'password_confirmation' => $this->fixturePassword('judge-second'),
        ]))->assertSessionHasErrors('email');

        $this->post('/login', ['email' => 'judge@example.test', 'password' => $this->fixturePassword('judge-first')])->assertRedirect('/');
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'judge@example.test']);
        $this->newToken($user);

        $this->post('/set-password', [
            'token' => 'not-the-token',
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('judge'),
            'password_confirmation' => $this->fixturePassword('judge'),
        ])->assertSessionHasErrors('email');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_link_expires_after_30_minutes(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'judge@example.test']);
        $token = $this->newToken($user);
        $form = [
            'token' => $token,
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('judge'),
            'password_confirmation' => $this->fixturePassword('judge'),
        ];

        $this->travel(31)->minutes();
        $this->post('/set-password', $form)->assertSessionHasErrors('email');

        $this->travelBack();
        $this->travel(29)->minutes();
        $this->post('/set-password', $form)->assertRedirect('/login');
    }

    public function test_a_short_or_mismatched_password_is_refused(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'judge@example.test']);
        $token = $this->newToken($user);

        $this->post('/set-password', [
            'token' => $token,
            'email' => 'judge@example.test',
            'password' => 'short-11-ch',
            'password_confirmation' => 'short-11-ch',
        ])->assertSessionHasErrors('password');

        $this->post('/set-password', [
            'token' => $token,
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('judge'),
            'password_confirmation' => 'a-different-password',
        ])->assertSessionHasErrors('password');

        // The refused tries do not spend the link.
        $this->post('/set-password', [
            'token' => $token,
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('judge'),
            'password_confirmation' => $this->fixturePassword('judge'),
        ])->assertRedirect('/login');
    }

    public function test_inviting_the_same_address_twice_makes_one_account(): void
    {
        Notification::fake();

        $this->withHeaders($this->admin())->post('/admin/invites', ['email' => 'judge@example.test']);
        // A second invite inside a minute is held back.
        $this->withHeaders($this->admin())->post('/admin/invites', ['email' => 'JUDGE@example.test'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 1);
        Notification::assertSentToTimes(User::first(), JudgeInvite::class, 1);
    }

    public function test_an_invalid_email_is_refused_and_creates_nothing(): void
    {
        Notification::fake();

        $this->withHeaders($this->admin())->post('/admin/invites', ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 0);
        Notification::assertNothingSent();
    }

    public function test_the_signed_in_judge_is_sent_past_the_set_password_page(): void
    {
        $this->signIn()->get('/set-password/anything?email=judge@example.test')->assertRedirect('/');
    }

    public function test_the_token_is_long_random_and_stored_only_as_a_hash(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'judge@example.test']);
        $first = $this->newToken($user);
        $second = $this->newToken($user);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->assertNotSame($first, $second);

        $stored = DB::table('password_reset_tokens')->where('email', 'judge@example.test')->get();
        $this->assertCount(1, $stored);
        $this->assertNotSame($second, $stored->first()->token);
        $this->assertTrue(Hash::check($second, $stored->first()->token));
    }

    public function test_the_page_checks_the_link_before_it_shows_the_form(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'judge@example.test']);
        $token = $this->newToken($user);

        $this->get('/set-password/'.$token.'?email=judge@example.test')
            ->assertOk()
            ->assertSee('name="password"', false);

        // A wrong token, an unknown email and a missing email look the same.
        $same = null;
        foreach ([
            '/set-password/wrong-token?email=judge@example.test',
            '/set-password/'.$token.'?email=nobody@example.test',
            '/set-password/'.$token,
        ] as $url) {
            $response = $this->get($url)
                ->assertStatus(410)
                ->assertDontSee('name="password"', false)
                ->assertSee('This link is not valid.');
            $same ??= $response->getContent();
            $this->assertSame(
                preg_replace('/(value|nonce|content)="[^"]*"/', '', $same),
                preg_replace('/(value|nonce|content)="[^"]*"/', '', $response->getContent()),
            );
        }
    }

    public function test_a_spent_or_expired_link_shows_no_form(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'judge@example.test']);
        $token = $this->newToken($user);
        $url = '/set-password/'.$token.'?email=judge@example.test';

        $this->travel(31)->minutes();
        $this->get($url)->assertStatus(410)->assertDontSee('name="password"', false);
        $this->travelBack();

        $this->post('/set-password', [
            'token' => $token,
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('judge'),
            'password_confirmation' => $this->fixturePassword('judge'),
        ])->assertRedirect('/login');

        $this->get($url)->assertStatus(410)->assertDontSee('name="password"', false);
    }

    public function test_a_successful_reset_ends_every_other_session_of_the_account(): void
    {
        $user = User::factory()->create([
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('old'),
        ]);

        // A session the judge opened earlier.
        $this->post('/login', ['email' => $user->email, 'password' => $this->fixturePassword('old')])->assertRedirect('/');
        $this->get('/')->assertOk();
        $oldSession = session()->all();
        $this->post('/logout');
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        // The judge sets a new password from a fresh link.
        $this->post('/set-password', [
            'token' => $this->newToken($user),
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('new-phrase'),
            'password_confirmation' => $this->fixturePassword('new-phrase'),
        ])->assertRedirect('/login');

        // The old password no longer works, and a session carrying the old
        // password hash is refused.
        $this->post('/login', ['email' => $user->email, 'password' => $this->fixturePassword('old')])->assertSessionHasErrors('email');
        $this->withSession($oldSession + ['password_hash_web' => 'old-hash'])->actingAs($user->fresh())->get('/')->assertRedirect('/login');
    }
}
