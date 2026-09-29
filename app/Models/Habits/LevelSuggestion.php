<?php

namespace App\Models\Habits;

/**
 * A suggested level change (habits spec "Habits Scale Progressively"): `up`
 * after at least 80 % shown-up over the last 14 UTC-6 days at the current
 * level, `down` after the tolerant streak breaks. Only a suggestion: the
 * owner applies it.
 */
final readonly class LevelSuggestion
{
    /**
     * @param  'up'|'down'  $direction
     */
    public function __construct(
        public string $direction,
        public int $toLevel,
        public string $label,
        public int $cast,
        public int $possible,
    ) {}

    /**
     * @return array{direction: 'up'|'down', to_level: int, label: string, cast: int, possible: int}
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction,
            'to_level' => $this->toLevel,
            'label' => $this->label,
            'cast' => $this->cast,
            'possible' => $this->possible,
        ];
    }
}
