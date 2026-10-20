<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row each time an operator keeps a machine running after PRISM said
     * to stop it. Rows are never edited or deleted by the app.
     */
    public function up(): void
    {
        Schema::create('override_logs', function (Blueprint $table) {
            $table->id();
            $table->string('operator_name', 100);
            $table->text('reason');
            $table->string('machine', 60);
            $table->string('scenario', 30);
            $table->unsignedInteger('tick');
            $table->unsignedTinyInteger('risk_score');
            $table->string('action_shown');
            $table->json('reading');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('override_logs');
    }
};
