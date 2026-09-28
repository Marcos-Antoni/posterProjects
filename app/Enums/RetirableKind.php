<?php

namespace App\Enums;

/**
 * The kinds of element the retirement protocol handles, as the Retired view
 * names and filters them. `Capture` arrives with Phase 7 (capture inbox); it
 * is listed so the view's filter shows it from day one.
 */
enum RetirableKind: string
{
    case Task = 'task';
    case Milestone = 'milestone';
    case Plan = 'plan';
    case Habit = 'habit';
    case Objective = 'objective';
    case Capture = 'capture';

    /**
     * The singular Spanish name, as the Retired view prints it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Task => 'Tarea',
            self::Milestone => 'Hito',
            self::Plan => 'Plan',
            self::Habit => 'Hábito',
            self::Objective => 'Objetivo',
            self::Capture => 'Captura',
        };
    }

    /**
     * The plural Spanish name (filter buttons, median ages).
     */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::Task => 'Tareas',
            self::Milestone => 'Hitos',
            self::Plan => 'Planes',
            self::Habit => 'Hábitos',
            self::Objective => 'Objetivos',
            self::Capture => 'Capturas',
        };
    }

    /**
     * Grammatical gender, so past participles agree ("Archivada" / "Archivado").
     */
    public function isFeminine(): bool
    {
        return in_array($this, [self::Task, self::Capture], true);
    }

    /**
     * A past participle agreeing with this kind: `participle('Archivad')`.
     */
    public function participle(string $stem): string
    {
        return $stem.($this->isFeminine() ? 'a' : 'o');
    }
}
