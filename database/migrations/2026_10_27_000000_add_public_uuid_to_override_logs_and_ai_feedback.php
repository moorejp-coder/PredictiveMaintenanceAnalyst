<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Tables whose rows a user reaches through a URL or an API response. */
    private const TABLES = ['override_logs', 'ai_feedback'];

    /**
     * Gives each table a random public id (UUID). Routes and API responses use
     * the uuid. The integer id stays as the internal key for ordering and
     * joins and is never sent to a user. Existing rows get a uuid here.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('uuid')->nullable();
            });

            DB::table($table)->whereNull('uuid')->orderBy('id')->each(function ($row) use ($table) {
                DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid7()]);
            });

            Schema::table($table, function (Blueprint $t) {
                $t->uuid('uuid')->nullable(false)->change();
                $t->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique($table.'_uuid_unique');
                $t->dropColumn('uuid');
            });
        }
    }
};
