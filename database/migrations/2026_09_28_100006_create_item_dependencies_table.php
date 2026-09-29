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
        // "Completing the prerequisite unlocks the dependent". Cycles are
        // rejected by App\Actions\Items\AddDependency (recursive CTE); the
        // database backs the duplicate and self-edge rules.
        Schema::create('item_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prerequisite_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('dependent_id')->constrained('items')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['prerequisite_id', 'dependent_id']);
            $table->index('dependent_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'ALTER TABLE item_dependencies ADD CONSTRAINT item_dependencies_no_self_edge CHECK (prerequisite_id <> dependent_id)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_dependencies');
    }
};
