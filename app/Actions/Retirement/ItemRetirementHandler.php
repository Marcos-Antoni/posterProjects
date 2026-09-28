<?php

namespace App\Actions\Retirement;

use App\Actions\Items\AddItem;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\PlanStateRecalculator;
use App\Enums\ItemKind;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Enums\RetirableKind;
use App\Enums\RetirementDecision;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Retirement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retiring a task or milestone (retirement + unlock-graph specs):
 *
 * - archive as-is: it leaves alone; its edges stay as history, and a
 *   dependent left only with retired prerequisites becomes available.
 * - move: what it unlocked now hangs from another task the owner picks.
 * - split: two or more smaller items (title + 2-minute version each) are
 *   created in the same plan, inheriting its prerequisites and dependents.
 *
 * @implements RetirementHandler<Item>
 */
class ItemRetirementHandler implements RetirementHandler
{
    use GuardsObjectives;

    public function __construct(private PlanStateRecalculator $planStates) {}

    public function kind(Model $element): RetirableKind
    {
        return $element->kind === ItemKind::Milestone ? RetirableKind::Milestone : RetirableKind::Task;
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
        return $element->deriveState()->value;
    }

    public function decisions(Model $element): array
    {
        return [RetirementDecision::Move, RetirementDecision::Split, RetirementDecision::ArchiveAsIs];
    }

    public function defaultDecision(Model $element): ?RetirementDecision
    {
        return $this->openDependents($element)->isEmpty() ? RetirementDecision::ArchiveAsIs : null;
    }

    public function guard(Model $element): void
    {
        $this->ensureWritable($element->objective);
    }

    public function itemIds(Model $element): array
    {
        return [$element->id];
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
        $plan = $element->plan;
        $objective = $element->objective;

        return match (true) {
            $objective->state === ObjectiveState::Retired => "Primero devolvé su objetivo, “{$objective->title}”.",
            $plan->state === PlanState::Retired => "Primero devolvé su plan, “{$plan->title}”.",
            $objective->state === ObjectiveState::Closed => "El objetivo “{$objective->title}” está cerrado: reabrilo para devolver esto.",
            default => null,
        };
    }

    public function restore(Model $element, Retirement $retirement): void
    {
        $element->update(['retired_at' => null, 'is_active' => false]);

        $this->planStates->recalculate($element->plan->refresh());
    }

    /**
     * Retire one item (history row + mark), also used by plan and objective
     * cascades. Releases it from Now and closes its open focus session.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function retireOne(Item $item, string $reason, RetirementDecision $decision, ?array $payload, RetirementRecorder $recorder, ?Retirement $parent = null): Retirement
    {
        $retirement = $recorder->record($item, $reason, $decision, $payload, $parent);

        $item->update(['retired_at' => now(), 'is_active' => false]);
        $item->focusSessions()->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => 'retired']);

        return $retirement;
    }

    private function archive(Item $item, string $reason, RetirementRecorder $recorder): RetirementOutcome
    {
        $retirement = $this->retireOne($item, $reason, RetirementDecision::ArchiveAsIs, null, $recorder);

        $this->planStates->recalculate($item->plan);

        return new RetirementOutcome($retirement);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function move(Item $item, string $reason, array $payload, RetirementRecorder $recorder): RetirementOutcome
    {
        $dependents = $this->openDependents($item);

        if ($dependents->isEmpty()) {
            throw ValidationException::withMessages(['decision' => 'No hay nada que mover: no abre ninguna tarea. Elegí archivar tal cual.']);
        }

        $target = is_string($payload['target'] ?? null) ? Item::resolveForOwner($item->objective->user, $payload['target']) : null;

        if ($target === null || $target->is($item) || $target->objective->state !== ObjectiveState::Active) {
            throw ValidationException::withMessages(['target' => 'Elegí una tarea tuya, de un objetivo activo, que siga en el mapa.']);
        }

        if ($dependents->contains('id', $target->id)) {
            throw ValidationException::withMessages(['target' => "“{$target->title}” se abre con esta tarea: elegí otra de la que colgar lo que abre."]);
        }

        foreach ($dependents as $dependent) {
            $exists = ItemDependency::query()->where('prerequisite_id', $target->id)->where('dependent_id', $dependent->id)->exists();

            if ($exists) {
                continue;
            }

            if ($this->reaches($dependent->id, $target->id)) {
                throw ValidationException::withMessages([
                    'target' => "No se puede: armaría un círculo, porque “{$target->title}” se abre después de “{$dependent->title}”.",
                ]);
            }

            ItemDependency::query()->create(['prerequisite_id' => $target->id, 'dependent_id' => $dependent->id]);
        }

        $retirement = $this->retireOne($item, $reason, RetirementDecision::Move, [
            'target' => ['type' => 'item', 'id' => $target->id, 'key' => $target->key, 'title' => $target->title],
            'moved' => $dependents->map(fn (Item $dependent): array => ['id' => $dependent->id, 'title' => $dependent->title])->values()->all(),
        ], $recorder);

        $this->planStates->recalculate($item->plan);

        return new RetirementOutcome($retirement, moved: $dependents->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function split(Item $item, string $reason, array $payload, RetirementRecorder $recorder): RetirementOutcome
    {
        $parts = $this->validatedParts($payload['parts'] ?? null);

        $prerequisiteIds = $item->prerequisites()->pluck('items.id')->all();
        $dependentIds = $this->openDependents($item)->pluck('id')->all();
        $objective = $item->objective;

        $created = [];

        foreach ($parts as $part) {
            $new = Item::query()->create([
                'objective_id' => $item->objective_id,
                'plan_id' => $item->plan_id,
                'number' => $objective->allocateNextItemNumber(),
                'kind' => $item->kind,
                'title' => $part['title'],
                'two_minute_version' => $part['two_minute_version'],
                'position' => Item::nextPositionIn($item->plan),
            ]);

            $new->prerequisites()->attach($prerequisiteIds);
            $new->dependents()->attach($dependentIds);
            $new->setRelation('objective', $objective);

            $created[] = $new;
        }

        $retirement = $this->retireOne($item, $reason, RetirementDecision::Split, [
            'created' => array_map(fn (Item $new): array => ['id' => $new->id, 'key' => $new->key, 'title' => $new->title], $created),
        ], $recorder);

        $this->planStates->recalculate($item->plan);

        return new RetirementOutcome($retirement, created: $created);
    }

    /**
     * @return list<array{title: string, two_minute_version: string}>
     */
    private function validatedParts(mixed $parts): array
    {
        $parts = is_array($parts) ? array_values($parts) : [];

        if (count($parts) < 2) {
            throw ValidationException::withMessages(['parts' => 'Dividir necesita al menos dos partes, cada una con su versión de 2 minutos.']);
        }

        $errors = [];
        $clean = [];

        foreach ($parts as $index => $part) {
            $title = trim((string) (is_array($part) ? ($part['title'] ?? '') : ''));
            $twoMinute = trim((string) (is_array($part) ? ($part['two_minute_version'] ?? '') : ''));

            if ($title === '') {
                $errors["parts.{$index}.title"] = 'Falta el título.';
            }

            if ($twoMinute === '') {
                $errors["parts.{$index}.two_minute_version"] = AddItem::MISSING_TWO_MINUTE;
            }

            if (mb_strlen($title) > 255 || mb_strlen($twoMinute) > 255) {
                $errors["parts.{$index}.title"] = 'Cada parte tiene como máximo 255 caracteres.';
            }

            $clean[] = ['title' => $title, 'two_minute_version' => $twoMinute];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * The non-retired items this one unlocks.
     *
     * @return Collection<int, Item>
     */
    private function openDependents(Item $item): Collection
    {
        return $item->dependents()->orderBy('items.id')->get();
    }

    /**
     * Whether walking "unlocks" edges from `$fromId` reaches `$toId` (a new
     * edge to → from would close a circle): the recursive walk of design D4,
     * with UNION so it terminates on any graph.
     */
    private function reaches(int $fromId, int $toId): bool
    {
        return DB::selectOne(<<<'SQL'
            WITH RECURSIVE walk (id) AS (
                SELECT CAST(? AS BIGINT)
                UNION
                SELECT edges.dependent_id
                FROM item_dependencies AS edges
                INNER JOIN walk ON edges.prerequisite_id = walk.id
            )
            SELECT 1 AS found FROM walk WHERE id = ? LIMIT 1
            SQL, [$fromId, $toId]) !== null;
    }
}
