<?php

namespace App\Actions\Support;

/**
 * Who performs a domain action (design D3). Owner kinds are Marco himself on
 * the web or the mobile API; AI kinds are Claude through MCP or the bridge.
 */
enum ActorKind: string
{
    case OwnerWeb = 'owner-web';
    case OwnerApi = 'owner-api';
    case AiMcp = 'ai-mcp';
    case AiBridge = 'ai-bridge';

    public function isAi(): bool
    {
        return $this === self::AiMcp || $this === self::AiBridge;
    }
}
