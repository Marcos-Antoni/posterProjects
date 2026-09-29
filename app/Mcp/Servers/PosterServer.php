<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Captures\Capture;
use App\Mcp\Tools\Captures\ListInbox;
use App\Mcp\Tools\Captures\TriageCapture;
use App\Mcp\Tools\Graphs\GlobalGraph;
use App\Mcp\Tools\Graphs\ObjectiveGraph;
use App\Mcp\Tools\Habits\CreateHabit;
use App\Mcp\Tools\Habits\ListHabits;
use App\Mcp\Tools\Habits\LogHabitEntry;
use App\Mcp\Tools\Habits\LogTwoMinute;
use App\Mcp\Tools\Habits\RestoreHabit;
use App\Mcp\Tools\Habits\RetireHabit;
use App\Mcp\Tools\Habits\ShowHabit;
use App\Mcp\Tools\Habits\TodayHabits;
use App\Mcp\Tools\Habits\UpdateHabit;
use App\Mcp\Tools\Items\AddDependency;
use App\Mcp\Tools\Items\AddItems;
use App\Mcp\Tools\Items\CheckItem;
use App\Mcp\Tools\Items\RemoveDependency;
use App\Mcp\Tools\Items\ReplaceTwoMinute;
use App\Mcp\Tools\Items\ShowItem;
use App\Mcp\Tools\Items\StartItem;
use App\Mcp\Tools\Items\UncheckItem;
use App\Mcp\Tools\Items\UpdateItem;
use App\Mcp\Tools\Objectives\CreateObjective;
use App\Mcp\Tools\Objectives\ListObjectives;
use App\Mcp\Tools\Objectives\ShowObjective;
use App\Mcp\Tools\Objectives\UpdateObjective;
use App\Mcp\Tools\Plans\CreatePlan;
use App\Mcp\Tools\Plans\UpdatePlan;
use App\Mcp\Tools\Proposals\Propose;
use App\Mcp\Tools\Views\NowView;
use App\Mcp\Tools\Views\RetiredView;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Poster Projects')]
#[Version('2.0.0')]
#[Instructions('Marcos OS: Marco\'s single-user system of objectives, plans, tasks/milestones with 2-minute versions and "completing A unlocks B" dependencies, plus his habits. The JIRA surface (projects, board, backlog, sprints, labels, comments, calendar, trash) no longer exists. Read tools (list/show/graph/now-view/retired-view and the like) never change anything. Direct tools — create-objective, update-objective, create-plan, update-plan, add-items, update-item, add-dependency, remove-dependency, check-item, uncheck-item, log-habit-entry, log-two-minute, capture, start-item, replace-two-minute, triage-capture — apply immediately, only for what Marco explicitly asked or clearly agreed to; every one of them writes an audit entry. Retiring or restoring any element (objective, plan, item or habit) is the only thing the AI never applies on its own: it always goes through `propose` (kind "retire"), which Marco accepts or rejects from "Propuestas" (`/ai/proposals`) and which then applies exactly as proposed, in one transaction. Every response includes the absolute web URL of the resource.')]
class PosterServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        // --- phase 2: objectives and items ---
        ListObjectives::class,
        ShowObjective::class,
        ShowItem::class,
        CheckItem::class,
        UncheckItem::class,
        // --- end phase 2 ---
        // --- phase 3: now ---
        NowView::class,
        // --- end phase 3 ---
        // --- phase 5: unlock graphs ---
        ObjectiveGraph::class,
        GlobalGraph::class,
        // --- end phase 5 ---
        CreateHabit::class,
        UpdateHabit::class,
        TodayHabits::class,
        ListHabits::class,
        ShowHabit::class,
        LogHabitEntry::class,
        // --- phase 4: habits ---
        LogTwoMinute::class,
        // --- end phase 4 ---
        // --- phase 6: retirement (retire/restore replace archive/unarchive) ---
        RetireHabit::class,
        RestoreHabit::class,
        RetiredView::class,
        // --- end phase 6 ---
        // --- phase 7: capture inbox and reviews ---
        Capture::class,
        ListInbox::class,
        // --- end phase 7 ---
        // --- phase 8: AI proposals (trimmed slice) ---
        Propose::class,
        StartItem::class,
        ReplaceTwoMinute::class,
        TriageCapture::class,
        // --- end phase 8 ---
        // --- 2026-09-29 decision: the AI creates and edits structure
        // directly (audited); only retire/restore stay proposals ---
        CreateObjective::class,
        UpdateObjective::class,
        CreatePlan::class,
        UpdatePlan::class,
        AddItems::class,
        UpdateItem::class,
        AddDependency::class,
        RemoveDependency::class,
        // --- end 2026-09-29 decision ---
    ];
}
