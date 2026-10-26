<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CanonicalUrl;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JudgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /** The sign-in is off by default, so these tests turn it on. */
    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true]);
    }

    private function judge(?string $password = null): User
    {
        return User::factory()->create([
            'email' => 'judge@example.test',
            'password' => $password ?? $this->fixturePassword('correct'),
        ]);
    }

    public function test_a_guest_is_sent_to_the_login_page_from_the_dashboard(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_a_guest_gets_401_json_from_every_api_route(): void
    {
        $this->getJson('/api/readings?scenario=healthy&tick=0')->assertStatus(401);
        $this->getJson('/api/diagnosis?scenario=worn_tool&tick=59')->assertStatus(401);
        $this->getJson('/api/work-order?scenario=worn_tool&tick=59')->assertStatus(401);
        $this->postJson('/api/overrides', [])->assertStatus(401);
        $this->postJson('/api/ai-feedback', [])->assertStatus(401);
    }

    public function test_the_public_pages_stay_open_to_a_guest(): void
    {
        foreach (['/login', '/ai', '/faq', '/privacy', '/terms', '/sitemap.xml'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_the_sitemap_leaves_out_the_dashboard_while_the_sign_in_is_on(): void
    {
        $this->get('/sitemap.xml')
            ->assertSee(url('/faq'), false)
            ->assertDontSee('<loc>'.CanonicalUrl::for('/').'</loc>', false);
    }

    public function test_the_login_page_has_labeled_fields_and_is_not_indexed(): void
    {
        $this->get('/login')
            ->assertSee('<label for="email">Email</label>', false)
            ->assertSee('<label for="password">Password</label>', false)
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertSee('<link rel="canonical"', false);
    }

    public function test_the_right_password_signs_in_and_opens_the_dashboard(): void
    {
        $user = $this->judge();

        $this->post('/login', ['email' => $user->email, 'password' => $this->fixturePassword('correct')])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk();
    }

    public function test_a_wrong_password_is_refused_with_one_generic_message(): void
    {
        $user = $this->judge();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'The email or password is wrong.']);

        $this->assertGuest();

        // An unknown email gets the same message, so accounts are not revealed.
        $this->from('/login')
            ->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'The email or password is wrong.']);
    }

    public function test_login_is_limited_to_five_tries_a_minute(): void
    {
        $user = $this->judge();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(302);
        }

        $this->post('/login', ['email' => $user->email, 'password' => $this->fixturePassword('correct')])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_guesses_spread_over_many_addresses_still_lock_the_account(): void
    {
        $user = $this->judge();

        for ($i = 1; $i <= 15; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->post('/login', ['email' => $user->email, 'password' => 'wrong'])
                ->assertStatus(302);
        }

        // A sixteenth address with the right password is refused too.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => $this->fixturePassword('correct')])
            ->assertSessionHasErrors(['email' => 'Too many sign-in attempts. Wait 15 minutes, then try again.']);
        $this->assertGuest();

        // The lock ends after 15 minutes.
        $this->travel(16)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->post('/login', ['email' => $user->email, 'password' => $this->fixturePassword('correct')])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_password_change_ends_the_other_sessions_of_that_account(): void
    {
        $user = $this->judge();

        $this->post('/login', ['email' => $user->email, 'password' => $this->fixturePassword('correct')])
            ->assertRedirect('/');
        $this->get('/')->assertOk();

        $user->forceFill(['password' => $this->fixturePassword('changed')])->save();
        $this->app['auth']->forgetGuards();

        $this->get('/')->assertRedirect('/login');
    }

    public function test_a_session_ends_seven_days_after_sign_in_however_active(): void
    {
        $user = $this->judge();

        $this->post('/login', ['email' => $user->email, 'password' => $this->fixturePassword('correct')])
            ->assertRedirect('/');

        $this->travel(6)->days();
        $this->get('/')->assertOk();

        $this->travel(2)->days();
        $this->app['auth']->forgetGuards();
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_session_cookie_is_http_only_same_site_and_secure_in_production(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertSame(30, config('session.lifetime'));
        $this->assertSame(7, config('prism.session_max_days'));
    }

    public function test_the_invite_admin_page_locks_out_an_address_after_ten_wrong_passwords(): void
    {
        config(['prism.invite_admin_password' => $this->fixturePassword('admin')]);
        $wrong = ['Authorization' => 'Basic '.base64_encode('entrant:wrong')];
        $right = ['Authorization' => 'Basic '.base64_encode('entrant:'.$this->fixturePassword('admin'))];

        for ($i = 0; $i < 10; $i++) {
            $this->get('/admin/invites', $wrong)->assertStatus(401);
        }

        $this->get('/admin/invites', $wrong)->assertStatus(429);
        $this->get('/admin/invites', $right)->assertStatus(429);

        $this->travel(16)->minutes();
        $this->get('/admin/invites', $right)->assertOk();
    }

    public function test_the_override_log_page_locks_out_an_address_after_ten_wrong_passwords(): void
    {
        config(['prism.override_log_password' => $this->fixturePassword('override')]);
        $wrong = ['Authorization' => 'Basic '.base64_encode('judge:wrong')];

        // A visit with no password at all is the browser's first request, not a guess.
        for ($i = 0; $i < 12; $i++) {
            $this->get('/overrides')->assertStatus(401);
        }

        for ($i = 0; $i < 10; $i++) {
            $this->get('/overrides', $wrong)->assertStatus(401);
        }

        $this->get('/overrides', $wrong)->assertStatus(429);
    }

    public function test_sign_out_ends_the_session(): void
    {
        $user = $this->judge();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_dashboard_shows_a_sign_out_button_only_when_signed_in(): void
    {
        $this->get('/ai')->assertDontSee('Sign out');
        $this->signIn()->get('/ai')->assertSee('Sign out');
    }

    public function test_the_override_review_page_keeps_its_own_password(): void
    {
        config(['prism.override_log_password' => $this->fixturePassword('override')]);

        // A signed-in judge still needs the log password.
        $this->signIn()->get('/overrides')->assertStatus(401);
        $this->get('/overrides', ['Authorization' => 'Basic '.base64_encode('judge:'.$this->fixturePassword('override'))])->assertOk();
    }

    public function test_the_judge_seeder_makes_one_account_from_the_environment_values(): void
    {
        config([
            'prism.judge.name' => 'Contest Judge',
            'prism.judge.email' => 'judge@example.test',
            'prism.judge.password' => $this->fixturePassword('unique'),
        ]);

        $this->seed(JudgeSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $user = User::first();
        $this->assertSame('judge@example.test', $user->email);
        $this->assertNotSame($this->fixturePassword('unique'), $user->password);
        $this->post('/login', ['email' => 'judge@example.test', 'password' => $this->fixturePassword('unique')])
            ->assertRedirect('/');
    }

    public function test_running_the_judge_seeder_again_updates_the_password_without_a_second_account(): void
    {
        config(['prism.judge.email' => 'judge@example.test', 'prism.judge.password' => $this->fixturePassword('first')]);
        $this->seed(JudgeSeeder::class);

        config(['prism.judge.password' => $this->fixturePassword('second')]);
        $this->seed(JudgeSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->post('/login', ['email' => 'judge@example.test', 'password' => $this->fixturePassword('first')])
            ->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'judge@example.test', 'password' => $this->fixturePassword('second')])
            ->assertRedirect('/');
    }

    public function test_no_account_exists_when_the_judge_values_are_missing(): void
    {
        config(['prism.judge.email' => null, 'prism.judge.password' => null]);

        $this->seed(JudgeSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }
}
