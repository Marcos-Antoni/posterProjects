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
        // One row per owner per ISO week (UTC-6): one main priority
        // (objective or plan, morph aliases from AppServiceProvider) and at
        // most two maintenance standards (objective or habit), kept as a
        // small JSON list (reviews spec).
        Schema::create('weekly_priorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->unsignedSmallInteger('iso_year');
            $table->unsignedTinyInteger('iso_week');
            $table->string('main_type', 20);
            $table->unsignedBigInteger('main_id');
            $table->json('maintenance')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'iso_year', 'iso_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_priorities');
    }
};
