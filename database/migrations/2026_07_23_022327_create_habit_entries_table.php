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
        Schema::create('habit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained();
            $table->unsignedInteger('amount');
            $table->timestampTz('logged_at');
            $table->timestamps();

            $table->index(['habit_id', 'logged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('habit_entries');
    }
};
