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
        Schema::create('habit_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained();
            $table->index('habit_id');
            $table->date('entry_date');
            $table->unsignedInteger('accumulated_amount')->default(0);
            $table->unsignedInteger('completion_percent')->default(0);
            $table->boolean('completed')->default(false);
            $table->integer('planned_delta_minutes')->nullable();
            $table->timestamps();

            $table->unique(['habit_id', 'entry_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('habit_days');
    }
};
