<?php

namespace App\Models\Habits;

/**
 * Identity votes over a window (habits spec): `cast` shown-up opportunities
 * out of `possible` scheduled ones, of which `twoMinute` were shown up only
 * with the 2-minute version. A proportion — never a score or percentage.
 */
final readonly class VoteTally
{
    public function __construct(
        public int $cast,
        public int $possible,
        public int $twoMinute,
    ) {}

    public function plus(self $other): self
    {
        return new self($this->cast + $other->cast, $this->possible + $other->possible, $this->twoMinute + $other->twoMinute);
    }

    public static function none(): self
    {
        return new self(0, 0, 0);
    }
}
