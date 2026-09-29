<?php

namespace App\Actions\Retirement;

use App\Enums\RetirableKind;
use App\Enums\RetirementDecision;
use App\Models\Capture;
use App\Models\Retirement;
use Illuminate\Database\Eloquent\Model;

/**
 * Retiring a capture (capture-inbox spec, "Triage Converts Or Retires
 * Captures"): it has no content, so archive as-is is the only decision, the
 * same shape as `HabitRetirementHandler`. Registered in `AppServiceProvider`
 * (`RetirementHandlers::register(Capture::class, self::class)`).
 *
 * @implements RetirementHandler<Capture>
 */
class CaptureRetirementHandler implements RetirementHandler
{
    public function kind(Model $element): RetirableKind
    {
        return RetirableKind::Capture;
    }

    public function ownerId(Model $element): int
    {
        return $element->user_id;
    }

    /**
     * A capture never carries an objective (capture-inbox spec).
     */
    public function objectiveId(Model $element): ?int
    {
        return null;
    }

    public function title(Model $element): string
    {
        return $element->text;
    }

    public function priorState(Model $element): string
    {
        return 'untriaged';
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
