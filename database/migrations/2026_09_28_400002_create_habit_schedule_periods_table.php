<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated habit schedules (no debt, never rewrite the past): when the
 * owner changes a habit's recurrence, the schedule that was in force is kept
 * here with the last UTC-6 day it applied (`valid_until`, inclusive). Days
 * after the newest `valid_until` use the habit's own recurrence columns.
 * Past opportunities are always evaluated with the schedule in force then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habit_schedule_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->string('recurrence_type');
            $table->json('weekdays')->nullable();
            $table->unsignedTinyInteger('times_per_week')->nullable();
            $table->date('valid_until');
            $table->timestamps();

            $table->index(['habit_id', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_schedule_periods');
    }
};
