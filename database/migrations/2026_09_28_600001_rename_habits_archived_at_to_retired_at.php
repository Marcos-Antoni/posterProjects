<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Habits leave the bare archive for the retirement protocol (design D2,
     * habits spec): the marker becomes `retired_at`, with the same meaning of
     * "hidden, history kept". Existing values carry over unchanged.
     */
    public function up(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $table->renameColumn('archived_at', 'retired_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $table->renameColumn('retired_at', 'archived_at');
        });
    }
};
