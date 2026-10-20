<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\JudgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function judge(string $password = 'correct-horse-battery'): User
    {
        return User::factory()->create([
            'email' => 'judge@example.test',
            'password' => $password,
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

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
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

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertStatus(429);
        $this->assertGuest();
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
        config(['prism.override_log_password' => 'open-sesame']);

        // A signed-in judge still needs the log password.
        $this->signIn()->get('/overrides')->assertStatus(401);
        $this->get('/overrides', ['Authorization' => 'Basic '.base64_encode('judge:open-sesame')])->assertOk();
    }

    public function test_the_judge_seeder_makes_one_account_from_the_environment_values(): void
    {
        config([
            'prism.judge.name' => 'Contest Judge',
            'prism.judge.email' => 'judge@example.test',
            'prism.judge.password' => 'a-long-unique-password',
        ]);

        $this->seed(JudgeSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $user = User::first();
        $this->assertSame('judge@example.test', $user->email);
        $this->assertNotSame('a-long-unique-password', $user->password);
        $this->post('/login', ['email' => 'judge@example.test', 'password' => 'a-long-unique-password'])
            ->assertRedirect('/');
    }

    public function test_running_the_judge_seeder_again_updates_the_password_without_a_second_account(): void
    {
        config(['prism.judge.email' => 'judge@example.test', 'prism.judge.password' => 'first-password-value']);
        $this->seed(JudgeSeeder::class);

        config(['prism.judge.password' => 'second-password-value']);
        $this->seed(JudgeSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->post('/login', ['email' => 'judge@example.test', 'password' => 'first-password-value'])
            ->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'judge@example.test', 'password' => 'second-password-value'])
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
