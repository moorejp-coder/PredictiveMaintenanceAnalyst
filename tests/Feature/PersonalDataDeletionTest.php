<?php

namespace Tests\Feature;

use App\Models\OverrideLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Deletion requests (Privacy Policy, section 7). An account, its invite links
 * and its override entries leave the database for good, from the admin pages
 * or from artisan.
 */
class PersonalDataDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true]);
    }

    private function entry(?User $owner): OverrideLog
    {
        return OverrideLog::create([
            'user_id' => $owner?->id,
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'machine' => 'Milling Machine M-01',
            'scenario' => 'overload',
            'tick' => 59,
            'risk_score' => 90,
            'action_shown' => 'Stop the machine and inspect it now.',
            'reading' => ['torque_nm' => 50],
        ]);
    }

    public function test_an_admin_deletes_an_account_with_its_links_and_override_entries(): void
    {
        $judge = User::factory()->create(['email' => 'judge@example.test']);
        Password::broker('invites')->createToken($judge);
        $mine = $this->entry($judge);
        $mine->delete();
        $someoneElse = $this->entry(null);

        $this->actingAs($this->adminUser())
            ->post('/admin/accounts/delete', ['email' => 'Judge@Example.test'])
            ->assertRedirect('/admin/invites')
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('users', ['email' => 'judge@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'judge@example.test']);
        $this->assertDatabaseMissing('override_logs', ['id' => $mine->id]);
        $this->assertDatabaseHas('override_logs', ['id' => $someoneElse->id]);
    }

    public function test_the_admin_page_lists_a_delete_button_but_not_for_the_admin_itself(): void
    {
        $this->adminUser();
        $admin = User::factory()->create(['email' => 'admin@example.test']);
        User::factory()->create(['email' => 'judge@example.test']);

        $page = $this->actingAs($admin)->get('/admin/invites')->assertOk();
        $page->assertSee('value="judge@example.test"', false);
        $page->assertDontSee('value="'.$admin->email.'"', false);

        $this->post('/admin/accounts/delete', ['email' => $admin->email])->assertSessionHasErrors('email');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_only_an_admin_can_delete_accounts_or_erase_entries(): void
    {
        $judge = User::factory()->create(['email' => 'judge@example.test']);
        $log = $this->entry($judge);

        $this->post('/admin/accounts/delete', ['email' => 'judge@example.test'])->assertRedirect('/login');
        $this->post('/admin/overrides/'.$log->uuid.'/erase')->assertRedirect('/login');

        $this->actingAs($judge);
        $this->post('/admin/accounts/delete', ['email' => 'judge@example.test'])->assertForbidden();
        $this->post('/admin/overrides/'.$log->uuid.'/erase')->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $judge->id]);
        $this->assertDatabaseHas('override_logs', ['id' => $log->id]);
    }

    public function test_an_admin_erases_one_entry_for_good_even_after_its_owner_deleted_it(): void
    {
        $log = $this->entry(null);
        $log->delete();
        $kept = $this->entry(null);

        $this->actingAs($this->adminUser())
            ->get('/overrides')
            ->assertOk()
            ->assertSee('/admin/overrides/'.$log->uuid.'/erase', false);

        $this->post('/admin/overrides/'.$log->uuid.'/erase')->assertRedirect('/overrides');

        $this->assertDatabaseMissing('override_logs', ['id' => $log->id]);
        $this->assertDatabaseHas('override_logs', ['id' => $kept->id]);
    }

    public function test_the_demo_sample_log_shows_no_erase_button(): void
    {
        $this->get('/demo');
        $this->get('/overrides')->assertOk()->assertDontSee('/erase', false);
    }

    public function test_the_command_erases_an_accounts_entries_and_keeps_the_account(): void
    {
        $judge = User::factory()->create(['email' => 'judge@example.test']);
        $mine = $this->entry($judge);
        $deleted = $this->entry($judge);
        $deleted->delete();
        $other = $this->entry(null);

        $this->artisan('prism:erase-overrides', ['--account' => 'Judge@Example.test', '--force' => true])
            ->expectsOutput('Erased 2 override entries.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('override_logs', ['id' => $mine->id]);
        $this->assertDatabaseMissing('override_logs', ['id' => $deleted->id]);
        $this->assertDatabaseHas('override_logs', ['id' => $other->id]);
        $this->assertDatabaseHas('users', ['id' => $judge->id]);

        $this->artisan('prism:erase-overrides', ['--account' => 'nobody@example.test'])->assertFailed();
    }

    public function test_the_command_erases_an_entry_by_its_short_id_and_asks_first(): void
    {
        $log = $this->entry(null);
        $short = substr($log->uuid, -8);

        $this->artisan('prism:erase-overrides', ['--id' => [$short]])
            ->expectsConfirmation('Erase 1 override entry for good? This cannot be undone.', 'no')
            ->assertSuccessful();
        $this->assertDatabaseHas('override_logs', ['id' => $log->id]);

        $this->artisan('prism:erase-overrides', ['--id' => [$short], '--force' => true])->assertSuccessful();
        $this->assertDatabaseMissing('override_logs', ['id' => $log->id]);
    }

    public function test_the_delete_account_command(): void
    {
        $judge = User::factory()->create(['email' => 'judge@example.test']);
        $log = $this->entry($judge);

        $this->artisan('prism:delete-account', ['email' => 'nobody@example.test'])->assertFailed();

        $this->artisan('prism:delete-account', ['email' => 'judge@example.test', '--force' => true])->assertSuccessful();
        $this->assertDatabaseMissing('users', ['id' => $judge->id]);
        $this->assertDatabaseMissing('override_logs', ['id' => $log->id]);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }
}
