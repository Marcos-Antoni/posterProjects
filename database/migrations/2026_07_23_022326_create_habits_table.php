<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->index('user_id');
            $table->string('name');
            $table->string('habit_type');
            $table->string('unit')->nullable();
            $table->unsignedInteger('daily_target')->nullable();
            $table->string('recurrence_type');
            $table->json('weekdays')->nullable();
            $table->unsignedTinyInteger('times_per_week')->nullable();
            $table->time('planned_time')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('habits');
    }
};
