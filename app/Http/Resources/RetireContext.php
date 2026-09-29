<?php

namespace App\Http\Resources;

use App\Actions\Retirement\RetirementHandlers;
use App\Enums\ItemKind;
use App\Enums\ItemState;
use App\Enums\ObjectiveState;
use App\Enums\RetirementDecision;
use App\Models\Capture;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What the retire dialog (screen 23) needs to know about one element: its
 * description, which content decisions apply (with the default for an
 * element without content), what its content is, where it can move, and
 * the reasons the owner already used. Loaded on demand when the dialog
 * opens, so the element pages pay nothing for it.
 *
 * @phpstan-type Option array{value: string, label: string}
 * @phpstan-type ContentRow array{id: int, title: string}
 */
final class RetireContext
{
    public function __construct(
        private RetirementHandlers $handlers,
        private RetiredView $retiredView,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $owner, Model $element): array
    {
        $handler = $this->handlers->for($element);
        $kind = $handler->kind($element);
        $default = $handler->defaultDecision($element);

        [$subtitle, $content, $targets, $path] = match (true) {
            $element instanceof Item => $this->item($owner, $element),
            $element instanceof Plan => $this->plan($element),
            $element instanceof Objective => $this->objective($owner, $element),
            $element instanceof Habit => ['Hábito, con todo su historial.', [], [], null],
            $element instanceof Capture => ['Captura sin triar.', [], [], null], // phase 7
            default => ['', [], [], null],
        };

        return [
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'feminine' => $kind->isFeminine(),
            'title' => $handler->title($element),
            'subtitle' => $subtitle,
            'decisions' => array_map(fn (RetirementDecision $decision): array => [
                'value' => $decision->value,
                'label' => $decision->label(),
                'description' => $this->describe($element, $decision, count($content)),
            ], $handler->decisions($element)),
            'default_decision' => $default?->value,
            'content' => $content,
            'targets' => $targets,
            'path' => $path,
            'used_reasons' => $this->retiredView->usedReasons($owner),
        ];
    }

    /**
     * @return array{0: string, 1: list<ContentRow>, 2: list<Option>, 3: array{before: list<array{title: string, state: string}>, after: list<array{title: string, state: string}>}}
     */
    private function item(User $owner, Item $item): array
    {
        $item->loadMissing(['plan', 'objective']);
        $dependents = Item::query()->withState()->whereIn('items.id', $item->dependents()->select('items.id'))->orderBy('items.id')->get();
        $prerequisites = Item::query()->withState()->whereIn('items.id', $item->prerequisites()->select('items.id'))->orderBy('items.id')->get();
        $excluded = [$item->id, ...$dependents->pluck('id')->all()];

        $targets = Item::query()
            ->with('objective:id,key,title')
            ->whereHas('objective', fn ($query) => $query->where('user_id', $owner->id)->where('state', ObjectiveState::Active->value))
            ->whereNotIn('items.id', $excluded)
            ->whereNull('completed_at')
            ->orderByRaw('CASE WHEN items.objective_id = ? THEN 0 ELSE 1 END', [$item->objective_id])
            ->orderBy('objective_id')
            ->orderBy('number')
            ->limit(500)
            ->get()
            ->map(fn (Item $target): array => ['value' => $target->key, 'label' => "{$target->key} {$target->title}"])
            ->all();

        $targets = array_values($targets);

        $kindWord = $item->kind === ItemKind::Milestone ? 'Hito' : 'Tarea';
        $stateWord = mb_strtolower($item->deriveState()->label());
        $subtitle = "{$kindWord} {$item->number} de {$item->objective->title}, plan {$item->plan->title}. Está {$stateWord}.";

        $node = fn (Item $neighbour): array => ['title' => $neighbour->title, 'state' => $neighbour->state->value];

        return [
            $subtitle,
            self::content($dependents),
            $targets,
            ['before' => array_values($prerequisites->map($node)->all()), 'after' => array_values($dependents->map($node)->all())],
        ];
    }

    /**
     * @return array{0: string, 1: list<ContentRow>, 2: list<Option>, 3: null}
     */
    private function plan(Plan $plan): array
    {
        $items = Item::query()->withState()->where('plan_id', $plan->id)->orderBy('position')->orderBy('id')->get();
        $open = $items->reject(fn (Item $item): bool => $item->state === ItemState::Done)->count();

        $targets = Plan::query()
            ->where('objective_id', $plan->objective_id)
            ->whereKeyNot($plan->id)
            ->orderBy('position')
            ->get(['id', 'title'])
            ->map(fn (Plan $target): array => ['value' => (string) $target->id, 'label' => "Plan {$target->title}"])
            ->all();

        $targets = array_values($targets);

        $count = $items->count();
        $done = $count - $open;
        $subtitle = "Plan de {$plan->objective->title}".match (true) {
            $count === 0 => ', sin tareas.',
            default => ', con '.($open === 1 ? '1 tarea disponible' : "{$open} tareas disponibles")
                .($done === 0 ? '' : ($done === 1 ? ' y 1 hecha' : " y {$done} hechas")).'.',
        };

        return [$subtitle, self::content($items), $targets, null];
    }

    /**
     * @return array{0: string, 1: list<ContentRow>, 2: list<Option>, 3: null}
     */
    private function objective(User $owner, Objective $objective): array
    {
        $plans = $objective->plans()->get(['id', 'title']);

        $targets = Objective::query()
            ->where('user_id', $owner->id)
            ->where('state', ObjectiveState::Active->value)
            ->whereKeyNot($objective->id)
            ->orderBy('position')
            ->get(['key', 'title'])
            ->map(fn (Objective $target): array => ['value' => $target->key, 'label' => $target->title])
            ->all();

        $targets = array_values($targets);

        $subtitle = 'Objetivo '.$objective->key.match ($plans->count()) {
            0 => ', sin planes.',
            1 => ', con 1 plan.',
            default => ", con {$plans->count()} planes.",
        };

        return [$subtitle, self::content($plans), $targets, null];
    }

    /**
     * The option descriptions of screen 23, per kind.
     */
    private function describe(Model $element, RetirementDecision $decision, int $contentCount): string
    {
        return match (true) {
            $element instanceof Item => match ($decision) {
                RetirementDecision::Move => 'Lo que abre pasa a colgar de otra tarea que elijas.',
                RetirementDecision::Split => 'La reemplazás por dos o más tareas más chicas.',
                RetirementDecision::ArchiveAsIs => $contentCount === 0 ? 'Se retira sola.' : 'Se retira sola; lo que abre deja de esperarla.',
            },
            $element instanceof Plan => match ($decision) {
                RetirementDecision::Move => 'Sus tareas pasan a otro plan del mismo objetivo.',
                RetirementDecision::Split => 'Lo reemplazás por planes más chicos.',
                RetirementDecision::ArchiveAsIs => $contentCount === 0 ? 'Se retira solo.' : ($contentCount === 1 ? 'Su tarea se retira con la misma razón.' : "Sus {$contentCount} tareas se retiran con la misma razón."),
            },
            $element instanceof Objective => match ($decision) {
                RetirementDecision::Move => 'Sus planes pasan a otro objetivo activo; sus tareas toman números nuevos allá.',
                RetirementDecision::Split => '',
                RetirementDecision::ArchiveAsIs => $contentCount === 0 ? 'Se retira solo.' : 'Sus planes y tareas se retiran con la misma razón. Los hábitos no se tocan.',
            },
            $element instanceof Capture => 'Se retira sola; deja la lista de sin decidir.', // phase 7
            default => 'Se retira con todo su historial y deja de pedir registros.',
        };
    }

    /**
     * @param  iterable<Item|Plan>  $models
     * @return list<ContentRow>
     */
    private static function content(iterable $models): array
    {
        $rows = [];

        foreach ($models as $model) {
            $rows[] = ['id' => (int) $model->id, 'title' => $model->title];
        }

        return $rows;
    }
}
