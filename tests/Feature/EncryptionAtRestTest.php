<?php

namespace Tests\Feature;

use App\Models\OverrideLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a copy of the database file shows without APP_KEY: no password, no
 * override reason and no IP address in plain text.
 */
class EncryptionAtRestTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Supervisor Pat Rivera is watching the spindle.';

    private function entry(): OverrideLog
    {
        return OverrideLog::create([
            'reason' => self::REASON,
            'machine' => 'Milling Machine M-01',
            'scenario' => 'overload',
            'tick' => 59,
            'risk_score' => 90,
            'action_shown' => 'Stop the machine and inspect it now.',
            'reading' => ['torque_nm' => 50],
        ]);
    }

    public function test_passwords_are_stored_as_bcrypt_hashes(): void
    {
        $user = User::factory()->create(['password' => 'a long correct horse phrase']);

        $stored = (string) DB::table('users')->where('id', $user->id)->value('password');
        // $2y$ is bcrypt. The test suite lowers the cost to 4 for speed. The live
        // site uses BCRYPT_ROUNDS=12.
        $this->assertStringStartsWith('$2y$', $stored);
        $this->assertStringNotContainsString('correct horse', $stored);
    }

    public function test_an_override_reason_is_encrypted_in_the_database_and_plain_in_the_app(): void
    {
        $log = $this->entry();
        $log->update(['reason' => 'A second reason that is long enough.', 'original_reason' => self::REASON]);

        $row = DB::table('override_logs')->where('id', $log->id)->first();
        $this->assertStringNotContainsString('Pat Rivera', (string) $row->reason);
        $this->assertStringNotContainsString('Pat Rivera', (string) $row->original_reason);
        $this->assertSame(self::REASON, Crypt::decryptString((string) $row->original_reason));
        $this->assertSame('A second reason that is long enough.', $log->fresh()->reason);
    }

    public function test_the_ip_addresses_of_wrong_passwords_are_encrypted(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['failed_login_ips' => ['203.0.113.9']])->save();

        $raw = (string) DB::table('users')->where('id', $user->id)->value('failed_login_ips');
        $this->assertStringNotContainsString('203.0.113.9', $raw);
        $this->assertSame(['203.0.113.9'], $user->fresh()->failed_login_ips);
    }

    public function test_the_migration_encrypts_rows_written_before_it_and_runs_twice_safely(): void
    {
        $id = DB::table('override_logs')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'reason' => self::REASON,
            'machine' => 'Milling Machine M-01',
            'scenario' => 'overload',
            'tick' => 59,
            'risk_score' => 90,
            'action_shown' => 'Stop the machine and inspect it now.',
            'reading' => '{}',
        ]);
        $migration = require database_path('migrations/2026_11_03_000001_encrypt_sensitive_columns.php');

        $migration->up();
        $once = (string) DB::table('override_logs')->where('id', $id)->value('reason');
        $migration->up();

        $this->assertSame($once, (string) DB::table('override_logs')->where('id', $id)->value('reason'));
        $this->assertSame(self::REASON, OverrideLog::findOrFail($id)->reason);

        $migration->down();
        $this->assertSame(self::REASON, DB::table('override_logs')->where('id', $id)->value('reason'));
    }

    public function test_session_files_are_encrypted_by_default(): void
    {
        $this->assertTrue(config('session.encrypt'));
    }
}
