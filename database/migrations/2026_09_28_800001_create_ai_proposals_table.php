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
        // A major AI operation Marco has not yet decided (ai-operations spec,
        // trimmed Phase 8 slice): the AI's exact intended operation
        // (`kind`/`payload`), a human-readable Spanish `summary`, and its
        // `status` until Marco accepts or rejects it from the web. Nothing in
        // `payload` is applied until accepted (see `ApplyProposal`).
        Schema::create('ai_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('kind', 40);
            $table->json('payload');
            $table->text('summary');
            $table->string('status', 20)->default('pending');
            $table->string('source', 20)->default('mcp');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_proposals');
    }
};
