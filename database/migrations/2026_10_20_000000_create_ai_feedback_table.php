<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row each time a visitor rates an "Explain this reading" answer.
     * No name, no free text and no IP address is stored.
     */
    public function up(): void
    {
        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->string('step', 20);
            $table->string('rating', 20);
            $table->string('source', 20);
            $table->string('scenario', 30);
            $table->unsignedInteger('tick');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback');
    }
};
