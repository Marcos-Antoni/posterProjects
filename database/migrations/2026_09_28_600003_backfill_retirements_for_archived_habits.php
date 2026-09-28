<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Habits archived before the retirement protocol have `retired_at` (the
     * renamed `archived_at`) but no history row, so the Retired view would
     * never show them and they could never be restored. Give each one a
     * retirement row with a neutral reason, dated when it was archived.
     */
    public function up(): void
    {
        $now = now();

        DB::table('habits')
            ->whereNotNull('retired_at')
            ->whereNotExists(fn ($query) => $query->from('retirements')
                ->whereColumn('retirements.retirable_id', 'habits.id')
                ->where('retirements.retirable_type', 'habit')
                ->whereNull('retirements.restored_at'))
            ->orderBy('id')
            ->each(function (object $habit) use ($now): void {
                DB::table('retirements')->insert([
                    'user_id' => $habit->user_id,
                    'retirable_type' => 'habit',
                    'retirable_id' => $habit->id,
                    'kind' => 'habit',
                    'objective_id' => null,
                    'parent_id' => null,
                    'reason' => 'Archivado antes de que existieran las razones de retiro.',
                    'decision' => 'archive_as_is',
                    'decision_payload' => null,
                    'prior_state' => 'active',
                    'retired_at' => $habit->retired_at,
                    'restored_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    /**
     * The backfilled rows are recognisable by their reason.
     */
    public function down(): void
    {
        DB::table('retirements')
            ->where('retirable_type', 'habit')
            ->where('reason', 'Archivado antes de que existieran las razones de retiro.')
            ->delete();
    }
};
