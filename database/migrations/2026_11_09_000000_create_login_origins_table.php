<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each account has signed in from (App\Support\LoginOrigins), so a
     * sign-in from a new country or network can raise an alert. The network is
     * a keyed one-way code, not an IP address. The country is stored only when
     * a CDN header supplies it. Rows go when the account goes.
     */
    public function up(): void
    {
        Schema::create('login_origins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('network_hash', 32);
            $table->string('country', 2)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unique(['user_id', 'network_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_origins');
    }
};
