<?php

use App\Mcp\Servers\PosterServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Servers
|--------------------------------------------------------------------------
|
| The server is exposed over streamable HTTP and authenticates with the
| single Sanctum PAT generated at /settings/mcp-token. Example client
| configuration (.mcp.json for Claude Code / Claude Desktop):
|
| {
|     "mcpServers": {
|         "poster": {
|             "type": "http",
|             "url": "https://<APP_URL>/mcp",
|             "headers": {
|                 "Authorization": "Bearer <your token>"
|             }
|         }
|     }
| }
|
*/

Mcp::web('/mcp', PosterServer::class)->middleware('auth:sanctum');
