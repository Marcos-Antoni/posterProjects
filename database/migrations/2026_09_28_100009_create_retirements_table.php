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
        // One row per retired element (design D8), kept as history on restore.
        Schema::create('retirements', function (Blueprint $table) {
            $table->id();
            $table->morphs('retirable');
            $table->text('reason');
            $table->string('decision', 16);
            $table->json('decision_payload')->nullable();
            $table->string('prior_state', 16)->nullable();
            $table->timestamp('retired_at');
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retirements');
    }
};
