<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Retired view (retirement spec) filters by owner, kind, objective and
     * month, and shows what was retired together: those facts are snapshotted
     * on the row at retirement time so the view is one query per page, and a
     * cascaded child points at the retirement that carried it (`parent_id`).
     * The table is still empty (Phase 2 created it with no writer).
     */
    public function up(): void
    {
        Schema::table('retirements', function (Blueprint $table) {
            $table->foreignId('user_id')->after('id')->constrained();
            $table->string('kind', 16)->after('retirable_id');
            $table->foreignId('objective_id')->nullable()->after('kind')->constrained();
            $table->foreignId('parent_id')->nullable()->after('objective_id')->constrained('retirements');

            $table->index(['user_id', 'restored_at', 'retired_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('retirements', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'restored_at', 'retired_at']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('objective_id');
            $table->dropColumn('kind');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
