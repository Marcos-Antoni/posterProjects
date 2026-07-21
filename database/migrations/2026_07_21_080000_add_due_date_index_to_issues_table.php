<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds an index on `issues.due_date` — T-10.1's calendar query filters
     * on this column (`whereNotNull('due_date')->whereBetween(...)`) across
     * every issue the authenticated user's projects contain, so it's worth
     * indexing even though the column is nullable (most issues won't have
     * a due date set).
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['due_date']);
        });
    }
};
