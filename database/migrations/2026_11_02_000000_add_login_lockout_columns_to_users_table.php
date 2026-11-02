<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Account lockout. failed_login_attempts counts wrong passwords since the
     * last good sign-in, with the time of the first one and up to 10 IP
     * addresses they came from. At 10 the account is locked for 30 minutes
     * and the owner gets an email with a change-password link. Only a
     * SHA-256 hash of the link token is stored.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('first_failed_login_at')->nullable();
            $table->json('failed_login_ips')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('unlock_token_hash', 64)->nullable();
            $table->timestamp('unlock_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'first_failed_login_at', 'failed_login_ips', 'locked_at', 'unlock_token_hash', 'unlock_sent_at']);
        });
    }
};
