<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which signed-in account sent the override. Null for rows written before
     * this column existed, or while the sign-in is off. No foreign key, so a
     * deleted account never removes an override row.
     */
    public function up(): void
    {
        Schema::table('override_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('override_logs', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }
};
