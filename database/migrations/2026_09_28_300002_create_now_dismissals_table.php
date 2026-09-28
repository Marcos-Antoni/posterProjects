<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Cerrar por hoy" on Now: the item is not suggested again on that
     * America/Guatemala (UTC-6) day. One row per owner, item and day.
     */
    public function up(): void
    {
        Schema::create('now_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // No database FK to items on purpose: phase-2 tables must stay
            // droppable by their own down() (items are never deleted anyway).
            $table->unsignedBigInteger('item_id');
            $table->date('local_date');
            $table->timestamps();

            $table->unique(['user_id', 'item_id', 'local_date']);
            $table->index(['user_id', 'local_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('now_dismissals');
    }
};
