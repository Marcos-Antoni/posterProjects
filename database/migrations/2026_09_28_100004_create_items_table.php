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
        // Tasks and milestones. The state is DERIVED (design D2), never
        // stored: see App\Models\Item::scopeWithState(). `number` is unique
        // per objective and never reused (issues spec).
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('objective_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('kind', 10);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('two_minute_version');
            $table->date('target_date')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['objective_id', 'number']);
            $table->index(['plan_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
