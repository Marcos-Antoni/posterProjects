<?php

namespace App\Mcp\Support;

use App\Models\BoardColumn;
use App\Models\Comment;
use App\Models\Habit;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Project;
use App\Models\Sprint;

/**
 * The single place that maps a model to the absolute web URL (APP_URL
 * based) every MCP tool response links to. Resources without a page of
 * their own fall back to the closest page that shows them.
 */
class ResourceLinker
{
    public function project(Project $project): string
    {
        // Trashed projects have no live board — the trash page is where
        // the web shows (and restores) them.
        if ($project->trashed()) {
            return route('projects.trash');
        }

        return route('projects.board', ['project' => $project->key]);
    }

    public function issue(Issue $issue): string
    {
        return route('projects.issues.show', [
            'project' => $issue->project->key,
            'issueKey' => $issue->key,
        ]);
    }

    public function habit(Habit $habit): string
    {
        return route('habits.show', ['habit' => $habit->id]);
    }

    public function sprint(Sprint $sprint): string
    {
        return route('projects.backlog', ['project' => $sprint->project->key]);
    }

    public function boardColumn(BoardColumn $column): string
    {
        return route('projects.board', ['project' => $column->project->key]);
    }

    public function label(Label $label): string
    {
        return route('projects.labels.index', ['project' => $label->project->key]);
    }

    public function comment(Comment $comment): string
    {
        return $this->issue($comment->issue);
    }
}
