<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Session fixation, browser binding, "sign out everywhere" and cookie flags.
 */
class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true]);
    }

    private function judge(): User
    {
        return User::factory()->create([
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('correct'),
        ]);
    }

    private function login(User $user, string $agent = 'Browser-A'): void
    {
        $this->withHeader('User-Agent', $agent)
            ->post('/login', ['email' => $user->email, 'password' => $this->fixturePassword('correct')])
            ->assertRedirect('/');
    }

    public function test_a_new_session_id_is_issued_at_every_sign_in(): void
    {
        $user = $this->judge();

        $this->get('/login');
        $before = session()->getId();

        $this->login($user);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_a_session_ends_when_the_browser_changes(): void
    {
        $this->login($this->judge());

        $this->withHeader('User-Agent', 'Browser-A')->get('/')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('User-Agent', 'Some-Other-Program/1.0')->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_an_ip_change_is_logged_but_does_not_end_the_session(): void
    {
        $this->login($this->judge());
        Log::spy();

        $this->withHeader('User-Agent', 'Browser-A')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->get('/')
            ->assertOk();

        Log::shouldHaveReceived('notice')->withArgs(fn ($message) => $message === 'Session IP address changed')->once();
    }

    public function test_sign_out_everywhere_ends_other_sessions_and_keeps_this_one(): void
    {
        $user = $this->judge();

        // Browser A.
        $this->login($user, 'Browser-A');
        $this->withHeader('User-Agent', 'Browser-A')->get('/')->assertOk();
        $browserA = session()->all();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        // Browser B signs in and presses the button.
        $this->login($user, 'Browser-B');
        $this->withHeader('User-Agent', 'Browser-B')->get('/account')->assertOk()->assertSee('Sign out everywhere');
        $this->withHeader('User-Agent', 'Browser-B')
            ->post('/logout-everywhere', ['password' => $this->fixturePassword('correct')])
            ->assertRedirect('/account');

        // B stays signed in.
        $this->app['auth']->forgetGuards();
        $this->withHeader('User-Agent', 'Browser-B')->get('/')->assertOk();

        // A is refused on its next request.
        $this->app['auth']->forgetGuards();
        $this->withSession($browserA)->withHeader('User-Agent', 'Browser-A')->get('/')->assertRedirect('/login');
    }

    public function test_sign_out_everywhere_needs_the_right_password(): void
    {
        $this->login($this->judge());

        $this->withHeader('User-Agent', 'Browser-A')->from('/account')
            ->post('/logout-everywhere', ['password' => 'wrong'])
            ->assertSessionHasErrors(['password' => 'The password is wrong.']);
    }

    public function test_the_account_pages_need_a_real_sign_in_and_the_switch_on(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $this->post('/logout-everywhere', ['password' => 'x'])->assertRedirect('/login');

        config(['prism.require_login' => false]);
        $this->actingAs($this->judge())->get('/account')->assertNotFound();
    }

    public function test_every_cookie_is_secure_http_only_and_same_site(): void
    {
        config(['session.secure' => true]);

        $response = $this->get('/login');
        $cookies = $response->headers->getCookies();
        $this->assertNotEmpty($cookies);

        foreach ($cookies as $cookie) {
            $this->assertTrue($cookie->isSecure(), $cookie->getName().' must be Secure');
            $this->assertSame('lax', $cookie->getSameSite(), $cookie->getName().' must be SameSite');
        }

        $session = collect($cookies)->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($session);
        $this->assertTrue($session->isHttpOnly());
    }
}
