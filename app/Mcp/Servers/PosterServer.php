<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\BoardColumns\CreateBoardColumn;
use App\Mcp\Tools\BoardColumns\DeleteBoardColumn;
use App\Mcp\Tools\BoardColumns\ReorderBoardColumn;
use App\Mcp\Tools\BoardColumns\UpdateBoardColumn;
use App\Mcp\Tools\Comments\CreateComment;
use App\Mcp\Tools\Comments\DeleteComment;
use App\Mcp\Tools\Comments\UpdateComment;
use App\Mcp\Tools\Habits\ArchiveHabit;
use App\Mcp\Tools\Habits\CreateHabit;
use App\Mcp\Tools\Habits\ListHabits;
use App\Mcp\Tools\Habits\LogHabitEntry;
use App\Mcp\Tools\Habits\ShowHabit;
use App\Mcp\Tools\Habits\TodayHabits;
use App\Mcp\Tools\Habits\UnarchiveHabit;
use App\Mcp\Tools\Habits\UpdateHabit;
use App\Mcp\Tools\Issues\CreateIssue;
use App\Mcp\Tools\Issues\MoveIssue;
use App\Mcp\Tools\Issues\ShowIssue;
use App\Mcp\Tools\Issues\UpdateIssue;
use App\Mcp\Tools\Labels\AttachIssueLabel;
use App\Mcp\Tools\Labels\CreateLabel;
use App\Mcp\Tools\Labels\DeleteLabel;
use App\Mcp\Tools\Labels\DetachIssueLabel;
use App\Mcp\Tools\Labels\ListLabels;
use App\Mcp\Tools\Labels\RenameLabel;
use App\Mcp\Tools\Projects\ArchiveProject;
use App\Mcp\Tools\Projects\CreateProject;
use App\Mcp\Tools\Projects\ForceDeleteProject;
use App\Mcp\Tools\Projects\ListProjects;
use App\Mcp\Tools\Projects\ListTrashedProjects;
use App\Mcp\Tools\Projects\RestoreProject;
use App\Mcp\Tools\Projects\UpdateProject;
use App\Mcp\Tools\Sprints\CreateSprint;
use App\Mcp\Tools\Sprints\DeleteSprint;
use App\Mcp\Tools\Sprints\UpdateSprint;
use App\Mcp\Tools\Views\BacklogView;
use App\Mcp\Tools\Views\BoardView;
use App\Mcp\Tools\Views\CalendarView;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Poster Projects')]
#[Version('1.0.0')]
#[Instructions('Single-user Jira-style project tracker plus personal habit tracking. Tools mirror every action available in the web UI: projects (with trash), issues, board, backlog/sprints, labels, comments, calendar, and habits. Every tool response includes the absolute web URL of the affected resource.')]
class PosterServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListProjects::class,
        CreateProject::class,
        UpdateProject::class,
        ArchiveProject::class,
        ListTrashedProjects::class,
        RestoreProject::class,
        ForceDeleteProject::class,
        BoardView::class,
        BacklogView::class,
        CalendarView::class,
        CreateIssue::class,
        UpdateIssue::class,
        MoveIssue::class,
        ShowIssue::class,
        CreateComment::class,
        UpdateComment::class,
        DeleteComment::class,
        ListLabels::class,
        CreateLabel::class,
        RenameLabel::class,
        DeleteLabel::class,
        AttachIssueLabel::class,
        DetachIssueLabel::class,
        CreateSprint::class,
        UpdateSprint::class,
        DeleteSprint::class,
        CreateBoardColumn::class,
        UpdateBoardColumn::class,
        ReorderBoardColumn::class,
        DeleteBoardColumn::class,
        CreateHabit::class,
        UpdateHabit::class,
        ArchiveHabit::class,
        UnarchiveHabit::class,
        TodayHabits::class,
        ListHabits::class,
        ShowHabit::class,
        LogHabitEntry::class,
    ];
}
