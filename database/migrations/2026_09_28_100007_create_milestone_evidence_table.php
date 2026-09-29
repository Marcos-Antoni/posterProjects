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
        Schema::create('milestone_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->string('link', 2048)->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_evidence');
    }
};
