<?php

namespace App\Enums;

/**
 * The name of a Sanctum personal access token, which also doubles as its
 * sole ability. Every query over `$user->tokens()` — read, delete, or
 * create — MUST filter or set the name through this enum: it is the
 * single source of truth for the boundary between the `/api/v1` bearer
 * surface (`Mobile`) and the existing `/mcp` surface (`Mcp`).
 */
enum TokenName: string
{
    case Mcp = 'mcp';
    case Mobile = 'mobile';
}
