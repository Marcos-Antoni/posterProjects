<?php

namespace App\Actions\Support;

use App\Models\User;

/**
 * The value object every domain action receives (design D3): the surface
 * acting and the owner on whose data it acts. Web controllers, API
 * controllers and MCP tools all build one and call the same action.
 */
final readonly class Actor
{
    public function __construct(
        public ActorKind $kind,
        public User $user,
    ) {}

    public static function ownerWeb(User $user): self
    {
        return new self(ActorKind::OwnerWeb, $user);
    }

    public static function ownerApi(User $user): self
    {
        return new self(ActorKind::OwnerApi, $user);
    }

    public static function aiMcp(User $user): self
    {
        return new self(ActorKind::AiMcp, $user);
    }

    public static function aiBridge(User $user): self
    {
        return new self(ActorKind::AiBridge, $user);
    }

    public function isAi(): bool
    {
        return $this->kind->isAi();
    }

    public function isOwner(): bool
    {
        return ! $this->isAi();
    }
}
