<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keeps the log honest once an owner may edit or delete an entry. A delete
     * only sets deleted_at. The first edit keeps the original name and reason.
     * The password-protected review page shows both.
     */
    public function up(): void
    {
        Schema::table('override_logs', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable();
            $table->string('original_operator_name', 100)->nullable();
            $table->text('original_reason')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('override_logs', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['edited_at', 'original_operator_name', 'original_reason']);
        });
    }
};
