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
        // Every replaced 2-minute version: `text` is the version that was
        // replaced, `source` (owner | ai) who replaced it, `replaced_at` when.
        Schema::create('item_two_minute_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->string('source', 10);
            $table->timestamp('replaced_at');

            $table->index(['item_id', 'replaced_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_two_minute_history');
    }
};
