<?php

namespace App\Actions\Retirement;

use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\PlanStateRecalculator;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Enums\RetirableKind;
use App\Enums\RetirementDecision;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Retirement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Retiring a plan (retirement + plans specs):
 *
 * - archive as-is: its open items are retired with it, same reason.
 * - move: its open items go to another plan of the same objective, keeping
 *   their numbers and dependencies.
 * - split: two or more smaller plans replace it, each with a copy of its
 *   5-point plan and control map; they are active when the source was
 *   active/done with a complete 5-point plan (their tasks stay reachable by
 *   Now), draft otherwise; each open item goes to the part the owner
 *   assigned (the first one by default).
 *
 * Habits linked to the plan (Phase 4) are never retired by this: the habits
 * spec keeps the link informational.
 *
 * @implements RetirementHandler<Plan>
 */
class PlanRetirementHandler implements RetirementHandler
{
    use GuardsObjectives;

    public function __construct(
        private ItemRetirementHandler $items,
        private PlanStateRecalculator $planStates,
    ) {}

    public function kind(Model $element): RetirableKind
    {
        return RetirableKind::Plan;
    }

    public function ownerId(Model $element): int
    {
        return $element->objective->user_id;
    }

    public function objectiveId(Model $element): ?int
    {
        return $element->objective_id;
    }

    public function title(Model $element): string
    {
        return $element->title;
    }

    public function priorState(Model $element): string
    {
        return $element->state->value;
    }

    public function decisions(Model $element): array
    {
        return [RetirementDecision::Move, RetirementDecision::Split, RetirementDecision::ArchiveAsIs];
    }

    public function defaultDecision(Model $element): ?RetirementDecision
    {
        return $element->items()->exists() ? null : RetirementDecision::ArchiveAsIs;
    }

    public function guard(Model $element): void
    {
        $this->ensureWritable($element->objective);
    }

    public function itemIds(Model $element): array
    {
        return array_values($element->items()->pluck('items.id')->all());
    }

    public function retire(Model $element, string $reason, RetirementDecision $decision, array $payload, RetirementRecorder $recorder): RetirementOutcome
    {
        return match ($decision) {
            RetirementDecision::ArchiveAsIs => $this->archive($element, $reason, $recorder),
            RetirementDecision::Move => $this->move($element, $reason, $payload, $recorder),
            RetirementDecision::Split => $this->split($element, $reason, $payload, $recorder),
        };
    }

    public function restoreBlocker(Model $element): ?string
    {
        $objective = $element->objective;

        return match ($objective->state) {
            ObjectiveState::Retired => "Primero devolvé su objetivo, “{$objective->title}”.",
            ObjectiveState::Closed => "El objetivo “{$objective->title}” está cerrado: reabrilo para devolver esto.",
            default => null,
        };
    }

    public function restore(Model $element, Retirement $retirement): void
    {
        $prior = PlanState::tryFrom((string) $retirement->prior_state);

        $element->update(['state' => $prior === null || $prior === PlanState::Retired ? PlanState::Active : $prior]);
    }

    /**
     * Retire a plan and, in cascade, its open items (archive as-is). Also
     * used by the objective cascade.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function retireWithContent(Plan $plan, string $reason, RetirementDecision $decision, ?array $payload, RetirementRecorder $recorder, ?Retirement $parent = null): RetirementOutcome
    {
        $retirement = $recorder->record($plan, $reason, $decision, $payload, $parent);

        $cascaded = 0;

        foreach ($plan->items()->get() as $item) {
            $this->items->retireOne($item, $reason, RetirementDecision::ArchiveAsIs, ['with_parent' => true], $recorder, $retirement);
            $cascaded++;
        }

        $plan->update(['state' => PlanState::Retired]);

        return new RetirementOutcome($retirement, cascaded: $cascaded);
    }

    private function archive(Plan $plan, string $reason, RetirementRecorder $recorder): RetirementOutcome
    {
        $count = $plan->items()->count();

        return $this->retireWithContent($plan, $reason, RetirementDecision::ArchiveAsIs, ['items' => $count], $recorder);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function move(Plan $plan, string $reason, array $payload, RetirementRecorder $recorder): RetirementOutcome
    {
        $items = $plan->items()->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['decision' => 'No hay nada que mover: el plan no tiene tareas. Elegí archivar tal cual.']);
        }

        $target = is_numeric($payload['target'] ?? null)
            ? Plan::query()->where('objective_id', $plan->objective_id)->whereKeyNot($plan->id)->find((int) $payload['target'])
            : null;

        if ($target === null) {
            throw ValidationException::withMessages(['target' => 'Elegí otro plan del mismo objetivo que siga en el mapa.']);
        }

        $this->handOver($items, $target);

        $retirement = $recorder->record($plan, $reason, RetirementDecision::Move, [
            'target' => ['type' => 'plan', 'id' => $target->id, 'title' => $target->title],
            'moved' => $items->count(),
        ]);

        $plan->update(['state' => PlanState::Retired]);
        $this->planStates->recalculate($target->refresh());

        return new RetirementOutcome($retirement, moved: $items->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function split(Plan $plan, string $reason, array $payload, RetirementRecorder $recorder): RetirementOutcome
    {
        $parts = is_array($payload['parts'] ?? null) ? array_values($payload['parts']) : [];

        if (count($parts) < 2) {
            throw ValidationException::withMessages(['parts' => 'Dividir necesita al menos dos planes nuevos, cada uno con su título.']);
        }

        $titles = [];

        foreach ($parts as $index => $part) {
            $title = trim((string) (is_array($part) ? ($part['title'] ?? '') : ''));

            if ($title === '' || mb_strlen($title) > 255) {
                throw ValidationException::withMessages(["parts.{$index}.title" => 'Cada plan nuevo necesita un título (hasta 255 caracteres).']);
            }

            $titles[] = $title;
        }

        $plan->loadMissing(['controlPlan', 'controlMapEntries']);
        $source = $plan->controlPlan;
        $complete = $source !== null && $source->isComplete();
        $created = [];

        foreach ($titles as $title) {
            $new = Plan::query()->create([
                'objective_id' => $plan->objective_id,
                'title' => $title,
                'state' => PlanState::Draft,
                'level' => $plan->level,
                'position' => Plan::nextPositionIn($plan->objective),
            ]);

            $this->copyControl($plan, $new);

            $created[] = $new;
        }

        $assignments = is_array($payload['assignments'] ?? null) ? $payload['assignments'] : [];
        $items = $plan->items()->get();

        foreach ($items as $item) {
            $index = (int) ($assignments[$item->id] ?? 0);
            $this->handOver(new Collection([$item]), $created[$index] ?? $created[0]);
        }

        foreach ($created as $new) {
            $new->update(['state' => $this->partState($new, $plan->state, $complete)]);
        }

        $retirement = $recorder->record($plan, $reason, RetirementDecision::Split, [
            'created' => array_map(fn (Plan $new): array => ['id' => $new->id, 'title' => $new->title], $created),
            'moved' => $items->count(),
        ]);

        $plan->update(['state' => PlanState::Retired]);

        return new RetirementOutcome($retirement, created: $created, moved: $items->count());
    }

    /**
     * The state of a split part once its items are handed over: a draft
     * source or an empty part gives a draft; a part whose items are all done
     * is done; otherwise it is active only with a complete 5-point plan
     * (plans spec), else draft.
     */
    private function partState(Plan $part, PlanState $sourceState, bool $completeControlPlan): PlanState
    {
        $total = $part->items()->count();
        $open = $part->items()->whereNull('completed_at')->count();

        return match (true) {
            $sourceState === PlanState::Draft, $total === 0 => PlanState::Draft,
            $open === 0 => PlanState::Done,
            $completeControlPlan => PlanState::Active,
            default => PlanState::Draft,
        };
    }

    /**
     * A part starts from the source plan's 5-point plan and control map, so
     * an active part always satisfies the plans spec (control-plan).
     */
    private function copyControl(Plan $source, Plan $part): void
    {
        if ($source->controlPlan !== null) {
            $part->controlPlan()->create($source->controlPlan->only([
                'outcome', 'deadline', 'metric_name', 'metric_target', 'metric_current', 'risks', 'contingency',
            ]));
        }

        foreach ($source->controlMapEntries as $entry) {
            $part->controlMapEntries()->create($entry->only(['zone', 'text', 'position']));
        }
    }

    /**
     * Append items at the end of `$target`, keeping number and edges.
     *
     * @param  Collection<int, Item>  $items
     */
    private function handOver(Collection $items, Plan $target): void
    {
        foreach ($items as $item) {
            $item->update(['plan_id' => $target->id, 'position' => Item::nextPositionIn($target)]);
        }
    }
}
