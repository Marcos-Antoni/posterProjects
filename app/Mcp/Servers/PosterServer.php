<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Graphs\GlobalGraph;
use App\Mcp\Tools\Graphs\ObjectiveGraph;
use App\Mcp\Tools\Habits\ArchiveHabit;
use App\Mcp\Tools\Habits\CreateHabit;
use App\Mcp\Tools\Habits\ListHabits;
use App\Mcp\Tools\Habits\LogHabitEntry;
use App\Mcp\Tools\Habits\LogTwoMinute;
use App\Mcp\Tools\Habits\ShowHabit;
use App\Mcp\Tools\Habits\TodayHabits;
use App\Mcp\Tools\Habits\UnarchiveHabit;
use App\Mcp\Tools\Habits\UpdateHabit;
use App\Mcp\Tools\Items\CheckItem;
use App\Mcp\Tools\Items\ShowItem;
use App\Mcp\Tools\Items\UncheckItem;
use App\Mcp\Tools\Objectives\ListObjectives;
use App\Mcp\Tools\Objectives\ShowObjective;
use App\Mcp\Tools\Views\NowView;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Poster Projects')]
#[Version('2.0.0')]
#[Instructions('Marcos OS: Marco\'s single-user system of objectives, plans, tasks/milestones with 2-minute versions and "completing A unlocks B" dependencies, plus his habits. The JIRA surface (projects, board, backlog, sprints, labels, comments, calendar, trash) no longer exists. Every tool states its AI tier: `read` changes nothing; `minor` is applied directly and audited, and only for what Marco explicitly named; anything else is major and must be proposed to Marco, never applied. Every response includes the absolute web URL of the resource.')]
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
        ArchiveHabit::class,
        UnarchiveHabit::class,
        TodayHabits::class,
        ListHabits::class,
        ShowHabit::class,
        LogHabitEntry::class,
        // --- phase 4: habits ---
        LogTwoMinute::class,
        // --- end phase 4 ---
    ];
}
