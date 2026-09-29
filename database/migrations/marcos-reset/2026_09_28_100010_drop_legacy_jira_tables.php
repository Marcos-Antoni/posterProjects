<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the retired JIRA surface (design D1): projects, project_members,
 * sprints, board_columns, labels, issues, comments and issue_label.
 *
 * It lives OUTSIDE the default migration path on purpose: D1 says the legacy
 * tables are dropped "by a single migration that only runs after the guarded
 * reset", so a plain `php artisan migrate` on deploy never touches them. Only
 * `php artisan marcos:reset` runs it (`--path=database/migrations/marcos-reset`),
 * after every guard passed. `down()` recreates the empty legacy schema exactly
 * as the legacy migrations left it, so `pg_restore` of the verified dump can
 * repopulate it on rollback.
 */
return new class extends Migration
{
    /**
     * The legacy tables in dependency order (children first).
     *
     * @var list<string>
     */
    public const TABLES = [
        'issue_label',
        'comments',
        'issues',
        'labels',
        'board_columns',
        'sprints',
        'project_members',
        'projects',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users');
            $table->index('owner_id');
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('next_issue_number')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();

            $table->index('project_id');
            $table->index('user_id');
            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('sprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('goal')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->index('project_id');
        });

        Schema::create('board_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->index('project_id');
            $table->string('name');
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['project_id', 'position']);
        });

        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->index('project_id');
            $table->string('name');
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->index('project_id');
            $table->foreignId('board_column_id')->constrained();
            $table->index('board_column_id');
            $table->foreignId('sprint_id')->nullable()->constrained()->nullOnDelete();
            $table->index('sprint_id');
            $table->foreignId('parent_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->index('parent_id');
            $table->unsignedInteger('number');
            $table->string('type');
            $table->unsignedTinyInteger('priority');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('story_points')->nullable();
            $table->date('due_date')->nullable();
            $table->index('due_date');
            $table->foreignId('assignee_id')->nullable()->constrained('users');
            $table->index('assignee_id');
            $table->foreignId('reporter_id')->constrained('users');
            $table->index('reporter_id');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'number']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->index('issue_id');
            $table->foreignId('user_id')->constrained();
            $table->index('user_id');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('issue_label', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index('issue_id');
            $table->index('label_id');
            $table->unique(['issue_id', 'label_id']);
        });
    }
};
