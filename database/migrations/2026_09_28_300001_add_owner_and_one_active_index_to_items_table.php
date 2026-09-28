<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Exactly one active task at a time" per owner, across all objectives
     * (now-focus spec, design D2/D6), enforced by the database: items get a
     * denormalized `user_id` (always the objective's owner, filled by a
     * trigger so no insert path can forget it) and a partial unique index
     * over it WHERE `is_active`.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::statement('UPDATE items SET user_id = objectives.user_id FROM objectives WHERE objectives.id = items.objective_id');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION items_fill_user_id() RETURNS trigger AS $$
            BEGIN
                NEW.user_id := (SELECT user_id FROM objectives WHERE id = NEW.objective_id);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER items_fill_user_id
                BEFORE INSERT OR UPDATE OF objective_id, user_id ON items
                FOR EACH ROW EXECUTE FUNCTION items_fill_user_id();
            SQL);

        DB::statement('ALTER TABLE items ALTER COLUMN user_id SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX items_one_active_per_user ON items (user_id) WHERE is_active');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS items_one_active_per_user');
        DB::unprepared('DROP TRIGGER IF EXISTS items_fill_user_id ON items; DROP FUNCTION IF EXISTS items_fill_user_id();');

        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
