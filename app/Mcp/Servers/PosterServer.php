<?php

namespace App\Mcp\Servers;

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
    protected array $tools = [];
}
