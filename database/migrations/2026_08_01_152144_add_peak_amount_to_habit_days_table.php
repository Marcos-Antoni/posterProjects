<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('habit_days', function (Blueprint $table) {
            $table->unsignedInteger('peak_amount')->default(0)->after('accumulated_amount');
        });

        // Postgres has no unsigned integers: Laravel's grammar silently drops the
        // modifier and this resolves to int4, exactly like `accumulated_amount`
        // (2026_07_23_022328:19). The declaration documents intent; it enforces
        // NOTHING. The zero floor is application code under the row lock.
        //
        // Backfill (idempotent — `GREATEST` with the current value):
        //  1. accumulated_amount is the true high-water mark for every pre-change
        //     row, because the accumulator was monotone until this migration.
        //  2. The `completed` clamp repairs rows whose habit's daily_target was
        //     raised (or whose habit_type flipped) after the day was recorded.
        //     Without it those rows carry peak < target and the first decrement
        //     erases their completion. See design D-2.
        DB::statement(<<<'SQL'
            UPDATE habit_days
            SET peak_amount = GREATEST(
                habit_days.peak_amount,
                habit_days.accumulated_amount,
                CASE WHEN habit_days.completed THEN
                    CASE WHEN habits.habit_type = 'quantitative'
                         THEN GREATEST(1, COALESCE(habits.daily_target, 0))
                         ELSE 1 END
                ELSE 0 END
            )
            FROM habits
            WHERE habits.id = habit_days.habit_id
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('habit_days', function (Blueprint $table) {
            $table->dropColumn('peak_amount');
        });
    }
};
