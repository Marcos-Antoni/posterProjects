<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds `deleted_at` to `projects` (archiving) and redefines the FKs
     * that must cascade when a project is permanently deleted
     * (`forceDelete`), so the whole project subtree is removed atomically
     * by the database instead of the application. `issues.board_column_id`
     * is deliberately left as-is (restrict) — column deletion with issue
     * reassignment is handled by application logic in T-9.5, not here.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('board_columns', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::table('sprints', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::table('labels', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::table('project_members', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();

            $table->dropForeign(['sprint_id']);
            $table->foreign('sprint_id')->references('id')->on('sprints')->nullOnDelete();

            $table->dropForeign(['parent_id']);
            $table->foreign('parent_id')->references('id')->on('issues')->nullOnDelete();
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropForeign(['issue_id']);
            $table->foreign('issue_id')->references('id')->on('issues')->cascadeOnDelete();
        });

        Schema::table('issue_label', function (Blueprint $table) {
            $table->dropForeign(['issue_id']);
            $table->foreign('issue_id')->references('id')->on('issues')->cascadeOnDelete();

            $table->dropForeign(['label_id']);
            $table->foreign('label_id')->references('id')->on('labels')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations — restores the original (non-cascading) FKs
     * and drops `deleted_at`. Never edit an applied migration; add a new
     * one instead.
     */
    public function down(): void
    {
        Schema::table('issue_label', function (Blueprint $table) {
            $table->dropForeign(['label_id']);
            $table->foreign('label_id')->references('id')->on('labels');

            $table->dropForeign(['issue_id']);
            $table->foreign('issue_id')->references('id')->on('issues');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropForeign(['issue_id']);
            $table->foreign('issue_id')->references('id')->on('issues');
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->foreign('parent_id')->references('id')->on('issues');

            $table->dropForeign(['sprint_id']);
            $table->foreign('sprint_id')->references('id')->on('sprints');

            $table->dropForeign(['project_id']);
            $table->foreign('project_id')->references('id')->on('projects');
        });

        Schema::table('project_members', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->foreign('project_id')->references('id')->on('projects');
        });

        Schema::table('labels', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->foreign('project_id')->references('id')->on('projects');
        });

        Schema::table('sprints', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->foreign('project_id')->references('id')->on('projects');
        });

        Schema::table('board_columns', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->foreign('project_id')->references('id')->on('projects');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
