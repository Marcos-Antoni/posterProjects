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
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained();
            $table->index('project_id');
            $table->foreignId('board_column_id')->constrained();
            $table->index('board_column_id');
            $table->foreignId('sprint_id')->nullable()->constrained();
            $table->index('sprint_id');
            $table->foreignId('parent_id')->nullable()->constrained('issues');
            $table->index('parent_id');
            $table->unsignedInteger('number');
            $table->string('type');
            $table->unsignedTinyInteger('priority');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('story_points')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users');
            $table->index('assignee_id');
            $table->foreignId('reporter_id')->constrained('users');
            $table->index('reporter_id');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
