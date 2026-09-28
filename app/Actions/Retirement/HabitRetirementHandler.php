<?php

namespace App\Actions\Retirement;

use App\Enums\RetirableKind;
use App\Enums\RetirementDecision;
use App\Models\Habit;
use App\Models\Retirement;
use Illuminate\Database\Eloquent\Model;

/**
 * Retiring a habit (habits spec "Retiring A Habit Follows The Retirement
 * Protocol"): it has no content, so archive as-is is the only decision; it
 * keeps its full history and rejects entries until restored.
 *
 * @implements RetirementHandler<Habit>
 */
class HabitRetirementHandler implements RetirementHandler
{
    public function kind(Model $element): RetirableKind
    {
        return RetirableKind::Habit;
    }

    public function ownerId(Model $element): int
    {
        return $element->user_id;
    }

    /**
     * Phase 4 adds `habits.objective_id`; until then a habit has none.
     */
    public function objectiveId(Model $element): ?int
    {
        $objectiveId = $element->getAttributes()['objective_id'] ?? null;

        return is_numeric($objectiveId) ? (int) $objectiveId : null;
    }

    public function title(Model $element): string
    {
        return $element->name;
    }

    public function priorState(Model $element): string
    {
        return 'active';
    }

    public function decisions(Model $element): array
    {
        return [RetirementDecision::ArchiveAsIs];
    }

    public function defaultDecision(Model $element): ?RetirementDecision
    {
        return RetirementDecision::ArchiveAsIs;
    }

    public function guard(Model $element): void {}

    public function itemIds(Model $element): array
    {
        return [];
    }

    public function retire(Model $element, string $reason, RetirementDecision $decision, array $payload, RetirementRecorder $recorder): RetirementOutcome
    {
        $retirement = $recorder->record($element, $reason, RetirementDecision::ArchiveAsIs);

        $element->update(['retired_at' => now()]);

        return new RetirementOutcome($retirement);
    }

    public function restoreBlocker(Model $element): ?string
    {
        return null;
    }

    public function restore(Model $element, Retirement $retirement): void
    {
        $element->update(['retired_at' => null]);
    }
}
