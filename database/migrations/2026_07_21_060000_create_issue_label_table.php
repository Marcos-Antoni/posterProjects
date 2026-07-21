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
        Schema::create('issue_label', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained();
            $table->foreignId('label_id')->constrained();
            $table->timestamps();

            $table->index('issue_id');
            $table->index('label_id');
            $table->unique(['issue_id', 'label_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issue_label');
    }
};
