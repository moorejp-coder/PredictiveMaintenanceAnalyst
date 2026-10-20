<?php

namespace Tests\Feature;

use App\Support\CanonicalUrl;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sign-in is built but off by default (REQUIRE_LOGIN). These tests run
 * with it off and check the dashboard is public and the login is inactive.
 */
class LoginSwitchOffTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_setting_is_off_by_default(): void
    {
        $this->assertFalse(config('prism.require_login'));
    }

    public function test_a_guest_can_open_the_dashboard_and_the_api_routes(): void
    {
        $this->get('/')->assertOk();
        $this->getJson('/api/readings?scenario=healthy&tick=0')->assertOk();
        $this->getJson('/api/diagnosis?scenario=healthy&tick=0')->assertOk();
    }

    public function test_the_login_page_goes_back_to_the_dashboard(): void
    {
        $this->get('/login')->assertRedirect('/');
    }

    public function test_the_login_and_logout_posts_return_404(): void
    {
        $this->post('/login', ['email' => 'judge@example.test', 'password' => 'whatever-it-is'])->assertNotFound();
        $this->post('/logout')->assertNotFound();
    }

    public function test_the_sitemap_lists_the_dashboard(): void
    {
        $this->get('/sitemap.xml')->assertSee('<loc>'.CanonicalUrl::for('/').'</loc>', false);
    }

    public function test_the_page_text_says_there_are_no_accounts(): void
    {
        $this->get('/privacy')->assertSee('PRISM has no accounts or sign-in.')->assertDontSee('judge account');
        $this->get('/terms')->assertSee('PRISM has no accounts.');
    }

    public function test_seeding_creates_no_user_without_judge_values(): void
    {
        config(['prism.judge.email' => null, 'prism.judge.password' => null]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }
}
