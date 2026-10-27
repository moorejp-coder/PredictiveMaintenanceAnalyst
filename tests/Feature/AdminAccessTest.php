<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true]);
    }

    public function test_a_guest_cannot_open_the_admin_pages(): void
    {
        $this->get('/admin/invites')->assertRedirect('/login');
        $this->getJson('/overrides')->assertUnauthorized();
    }

    public function test_a_signed_in_user_who_is_not_an_admin_gets_403_and_the_refusal_is_logged(): void
    {
        config(['prism.admin.email' => 'admin@example.test']);
        Log::spy();

        $this->signIn()->get('/admin/invites')->assertForbidden();

        Log::shouldHaveReceived('log')->once()->withArgs(
            fn (string $level, string $message): bool => $level === 'warning'
        );
    }

    public function test_an_admin_whose_email_is_not_verified_is_refused(): void
    {
        config(['prism.admin.email' => 'admin@example.test']);
        $user = User::factory()->unverified()->create(['email' => 'admin@example.test']);

        $this->actingAs($user)->get('/admin/invites')->assertForbidden();
    }

    public function test_the_admin_email_list_is_case_insensitive(): void
    {
        config(['prism.admin.email' => null, 'prism.admin.emails' => 'One@Example.test, two@example.test']);
        $user = User::factory()->create(['email' => 'TWO@example.test']);

        $this->actingAs($user)->get('/admin/invites')->assertOk();
        $this->assertTrue($user->isAdmin());
    }

    public function test_no_admin_setting_means_nobody_is_an_admin(): void
    {
        config(['prism.admin.email' => null, 'prism.admin.emails' => '']);

        $this->signIn()->get('/admin/invites')->assertForbidden();
        $this->get('/overrides')->assertForbidden();
    }

    public function test_an_admin_can_open_both_pages(): void
    {
        $this->actingAs($this->adminUser());

        $this->get('/admin/invites')->assertOk();
        $this->get('/overrides')->assertOk();
    }

    public function test_the_pages_do_not_exist_while_the_sign_in_is_off(): void
    {
        config(['prism.require_login' => false]);

        $this->actingAs($this->adminUser())->get('/overrides')->assertNotFound();
        $this->get('/admin/invites')->assertNotFound();
    }

    public function test_the_admin_seeder_makes_a_verified_admin_and_can_run_twice(): void
    {
        config([
            'prism.admin.email' => 'admin@example.test',
            'prism.admin.password' => $this->fixturePassword('admin-seed'),
            'prism.admin.name' => 'Seed Admin',
        ]);

        $this->seed(AdminSeeder::class);
        $this->seed(AdminSeeder::class);

        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertDatabaseCount('users', 1);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check($this->fixturePassword('admin-seed'), $user->password));
        $this->assertTrue($user->isAdmin());
    }

    public function test_the_admin_seeder_skips_a_short_password(): void
    {
        config(['prism.admin.email' => 'admin@example.test', 'prism.admin.password' => 'short']);

        $this->seed(AdminSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }
}
