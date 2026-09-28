<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (design D2): habits hang optionally from an objective and one of
 * its plans (informational link, no cascade), carry a 2-minute version, an
 * optional identity statement and a level ladder; a habit day records
 * whether the 2-minute version was logged (shown-up = completed OR
 * two_minute_logged). `level_started_on` is the UTC-6 date the current level
 * began, so a level-up is only suggested after 14 days lived at it.
 *
 * `two_minute_version` is nullable only for habits created before this
 * migration; the create/update actions require it.
 *
 * `objective_id` / `plan_id` are indexed but deliberately NOT database
 * foreign keys: the link is informational (nothing cascades from an
 * objective to its habits), objectives and plans are never deleted (only
 * retired), ownership and plan-of-objective are enforced by
 * `HabitWriter`, and a constraint would make the habits table a dependent
 * of the Phase 2 tables, which must stay droppable on their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $table->unsignedBigInteger('objective_id')->nullable()->after('user_id')->index();
            $table->unsignedBigInteger('plan_id')->nullable()->after('objective_id')->index();
            $table->string('two_minute_version')->nullable()->after('name');
            $table->string('identity_statement')->nullable()->after('two_minute_version');
            $table->unsignedSmallInteger('level')->nullable()->after('planned_time');
            $table->json('level_ladder')->nullable()->after('level');
            $table->date('level_started_on')->nullable()->after('level_ladder');
        });

        Schema::table('habit_days', function (Blueprint $table) {
            $table->boolean('two_minute_logged')->default(false)->after('completed');
        });
    }

    public function down(): void
    {
        Schema::table('habit_days', function (Blueprint $table) {
            $table->dropColumn('two_minute_logged');
        });

        Schema::table('habits', function (Blueprint $table) {
            $table->dropIndex(['plan_id']);
            $table->dropIndex(['objective_id']);
            $table->dropColumn(['objective_id', 'plan_id', 'two_minute_version', 'identity_statement', 'level', 'level_ladder', 'level_started_on']);
        });
    }
};
