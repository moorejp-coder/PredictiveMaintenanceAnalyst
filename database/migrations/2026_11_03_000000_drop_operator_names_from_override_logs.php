<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data minimization. The override form no longer asks for a typed name:
     * each entry already records the signed-in account that wrote it
     * (user_id), so the name repeated data PRISM had and was never verified.
     * Dropping the columns also removes every name typed before this change.
     */
    public function up(): void
    {
        Schema::table('override_logs', function (Blueprint $table) {
            $table->dropColumn(['operator_name', 'original_operator_name']);
        });
    }

    /** The columns come back empty. The dropped names are gone for good. */
    public function down(): void
    {
        Schema::table('override_logs', function (Blueprint $table) {
            $table->string('operator_name', 100)->nullable();
            $table->string('original_operator_name', 100)->nullable();
        });
    }
};
