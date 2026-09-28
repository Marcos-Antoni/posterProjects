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
        // Marcos OS root entity (design D2). `state`: draft | active | closed |
        // retired (App\Enums\ObjectiveState). The 5-point plan lives in
        // `control_plans`; `next_item_number` feeds the row-locked allocator.
        Schema::create('objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('key', 10)->unique();
            $table->string('title');
            $table->string('identity_statement')->nullable();
            $table->string('state', 10)->default('draft');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('next_item_number')->default(1);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'state', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('objectives');
    }
};
