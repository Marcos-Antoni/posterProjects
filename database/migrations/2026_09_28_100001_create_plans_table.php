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
        // `state`: draft | active | done | retired (App\Enums\PlanState).
        // `level` is the optional progression rung (Goldilocks scaling).
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('objective_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('state', 10)->default('draft');
            $table->unsignedSmallInteger('level')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['objective_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
