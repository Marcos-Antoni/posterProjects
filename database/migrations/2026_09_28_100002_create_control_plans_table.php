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
        // The Control 5-point plan of an objective or a plan. Every point is
        // nullable so a draft plan can be saved half-written; activation
        // (App\Domain\ControlPlanCompleteness) requires all five.
        Schema::create('control_plans', function (Blueprint $table) {
            $table->id();
            $table->morphs('plannable');
            $table->text('outcome')->nullable();
            $table->date('deadline')->nullable();
            $table->string('metric_name')->nullable();
            $table->decimal('metric_target', 14, 2)->nullable();
            $table->decimal('metric_current', 14, 2)->nullable();
            $table->json('risks')->nullable();
            $table->text('contingency')->nullable();
            $table->timestamps();

            $table->unique(['plannable_type', 'plannable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('control_plans');
    }
};
