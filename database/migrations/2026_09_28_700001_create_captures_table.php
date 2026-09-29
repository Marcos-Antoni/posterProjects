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
        // One field, no priority, no objective (capture-inbox spec). Kept as
        // history when triaged (`result_type`/`result_id`, the retirement
        // spec's morph aliases) or retired (`retired_at`, same pattern as
        // habits/items/plans/objectives — not in design.md's D2 table, added
        // here so Capture can use the same `HasRetirement`/`NotRetired` scope
        // as every other retirable kind, per design D8).
        Schema::create('captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('text', 500);
            $table->string('source', 10);
            $table->timestamp('triaged_at')->nullable();
            $table->string('result_type', 20)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'triaged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('captures');
    }
};
