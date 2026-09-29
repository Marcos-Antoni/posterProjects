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
        // Every AI-applied change is audited (ai-operations spec): a minor
        // operation applied directly by `DatabaseAuditWriter` (bound to
        // `AuditWriter`, called from `DomainTransaction` for an AI actor), or
        // a proposal decided from the web (`AcceptProposal`/`RejectProposal`).
        // `target_type` stores the short morph alias (AppServiceProvider's
        // morph map), not the FQCN.
        Schema::create('ai_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('source', 20);
            $table->string('tier', 10);
            $table->string('operation', 60);
            $table->string('target_type', 30)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('proposal_id')->nullable()->constrained('ai_proposals')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_audit_log');
    }
};
