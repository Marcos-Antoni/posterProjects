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
        // Weekly reviews and objective learning reviews, kept as history
        // forever (reviews spec: "Reviews Are Kept As History"). Milestone
        // summit evidence lives in `milestone_evidence`, not here.
        //
        // `objective_id` is a plain column, not a DB foreign key (same
        // deviation as `habits.objective_id`, phase 4): a review is history
        // and must stay readable even if its objective is later dropped by
        // an independent migration reversal (MigrationsTest re-applies only
        // the phase-2 tables); the app enforces the link.
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('kind', 10);
            $table->unsignedBigInteger('objective_id')->nullable();
            $table->json('answers');
            $table->timestamps();

            $table->index(['user_id', 'kind', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
