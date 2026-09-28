<?php

use App\Actions\Retirement\RetireElement;
use App\Actions\Support\Actor;
use App\Actions\Support\MajorOperationRequiresProposal;
use App\Enums\ItemKind;
use App\Enums\ItemState;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Enums\RetirableKind;
use App\Enums\RetirementDecision;
use App\Models\FocusSession;
use App\Models\Habit;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Retirement;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;

/*
| Task 6.1 — RetireElement (retirement spec, design D8): a written reason of
| at least 10 characters, one content decision (move / split / archive as-is),
| atomic, one `retirements` row per retired element with its prior state, and
| dependents recomputed (a retired prerequisite never blocks).
*/

function rtRetire(Actor $actor, $element, string $reason, ?RetirementDecision $decision = null, array $payload = [])
{
    return app(RetireElement::class)($actor, $element, $reason, $decision, $payload);
}

function rtEdge(Item $prerequisite, Item $dependent): void
{
    ItemDependency::query()->create(['prerequisite_id' => $prerequisite->id, 'dependent_id' => $dependent->id]);
}

beforeEach(function () {
    $this->objective = Objective::factory()->withControlPlan()->create(['key' => 'SALUD', 'title' => 'Salud']);
    $this->plan = Plan::factory()->for($this->objective)->create(['title' => 'Semana 1']);
    $this->owner = Actor::ownerWeb($this->objective->user);
});

test('retiring without a reason is rejected with a Spanish message and changes nothing', function (string $reason) {
    $item = Item::factory()->for($this->plan)->create();

    $errors = mosErrors(fn () => rtRetire($this->owner, $item, $reason));

    expect($errors['reason'][0])->toBe('Escribí al menos 10 caracteres para que te sirva después.')
        ->and($item->fresh()->retired_at)->toBeNull()
        ->and(Retirement::query()->count())->toBe(0);
})->with([
    'empty' => [''],
    'only spaces' => ['            '],
    'nine characters' => ['muy grand'],
    'nine characters padded' => ['   muy grand   '],
]);

test('a leaf task defaults to archive as-is and records reason, decision and prior state', function () {
    $item = Item::factory()->for($this->plan)->create(['title' => 'Migrar calendario']);

    $result = rtRetire($this->owner, $item, '  el calendario sale del producto  ');

    $item = Item::withRetired()->findOrFail($item->id);
    $retirement = $result->retirement;

    expect($item->retired_at)->not->toBeNull()
        ->and($item->state)->toBe(ItemState::Retired)
        ->and(Item::query()->find($item->id))->toBeNull()
        ->and($retirement->reason)->toBe('el calendario sale del producto')
        ->and($retirement->decision)->toBe(RetirementDecision::ArchiveAsIs)
        ->and($retirement->prior_state)->toBe('available')
        ->and($retirement->kind)->toBe(RetirableKind::Task)
        ->and($retirement->user_id)->toBe($this->objective->user_id)
        ->and($retirement->objective_id)->toBe($this->objective->id)
        ->and($retirement->retirable_type)->toBe('item')
        ->and($retirement->retirable_id)->toBe($item->id)
        ->and($retirement->restored_at)->toBeNull()
        ->and($retirement->parent_id)->toBeNull();
});

test('a milestone is recorded with the milestone kind', function () {
    $milestone = Item::factory()->for($this->plan)->milestone()->create();

    expect(rtRetire($this->owner, $milestone, 'ya no aplica a este plan')->retirement->kind)->toBe(RetirableKind::Milestone);
});

test('an item with dependents has no default decision: the owner must choose', function () {
    $a = Item::factory()->for($this->plan)->create();
    $b = Item::factory()->for($this->plan)->create();
    rtEdge($a, $b);

    $errors = mosErrors(fn () => rtRetire($this->owner, $a, 'demasiado grande para arrancar'));

    expect($errors)->toHaveKey('decision')
        ->and($a->fresh()->retired_at)->toBeNull();
});

test('archiving the only prerequisite unlocks the dependent', function () {
    $a = Item::factory()->for($this->plan)->create(['title' => 'A']);
    $b = Item::factory()->for($this->plan)->create(['title' => 'B']);
    rtEdge($a, $b);
    expect($b->fresh()->deriveState())->toBe(ItemState::Locked);

    $result = rtRetire($this->owner, $a, 'ya no hace falta esto', RetirementDecision::ArchiveAsIs);

    expect($b->fresh()->deriveState())->toBe(ItemState::Available)
        ->and($result->unlocked->pluck('id')->all())->toBe([$b->id]);
});

test('splitting replaces a task with smaller ones in the same plan, inheriting prerequisites and dependents', function () {
    $before = Item::factory()->for($this->plan)->done()->create(['title' => 'Captura 10 min']);
    $big = Item::factory()->for($this->plan)->create(['title' => 'Clasificar y elegir prioridad']);
    $after1 = Item::factory()->for($this->plan)->create(['title' => 'Revisar video']);
    $after2 = Item::factory()->for($this->plan)->create(['title' => 'Revisar fórmula']);
    rtEdge($before, $big);
    rtEdge($big, $after1);
    rtEdge($big, $after2);

    $result = rtRetire($this->owner, $big, 'demasiado grande: mezcla dos decisiones', RetirementDecision::Split, [
        'parts' => [
            ['title' => 'Clasificar las capturas del inbox', 'two_minute_version' => 'Mover una captura'],
            ['title' => 'Elegir la prioridad de la semana', 'two_minute_version' => 'Escribir un nombre'],
        ],
    ]);

    expect(Item::withRetired()->findOrFail($big->id)->retired_at)->not->toBeNull()
        ->and($result->created)->toHaveCount(2);

    foreach ($result->created as $part) {
        $part = Item::query()->findOrFail($part->id);

        expect($part->plan_id)->toBe($this->plan->id)
            ->and($part->objective_id)->toBe($this->objective->id)
            ->and($part->kind)->toBe(ItemKind::Task)
            ->and($part->prerequisites()->pluck('items.id')->all())->toBe([$before->id])
            ->and($part->dependents()->pluck('items.id')->sort()->values()->all())->toBe([$after1->id, $after2->id])
            ->and($part->number)->toBeGreaterThan(4)
            ->and($part->deriveState())->toBe(ItemState::Available);
    }

    expect($result->created->pluck('title')->all())->toBe(['Clasificar las capturas del inbox', 'Elegir la prioridad de la semana'])
        ->and($result->retirement->decision_payload['created'])->toHaveCount(2)
        // Both halves must be done before the old dependents open.
        ->and($after1->fresh()->deriveState())->toBe(ItemState::Locked);
});

test('a split needs at least two parts, each with a title and a 2-minute version, and is atomic', function (array $parts, string $errorKey) {
    $item = Item::factory()->for($this->plan)->create();

    $errors = mosErrors(fn () => rtRetire($this->owner, $item, 'demasiado grande de verdad', RetirementDecision::Split, ['parts' => $parts]));

    expect($errors)->toHaveKey($errorKey)
        ->and($item->fresh()->retired_at)->toBeNull()
        ->and(Item::withRetired()->count())->toBe(1)
        ->and(Retirement::query()->count())->toBe(0);
})->with([
    'no parts' => [[], 'parts'],
    'one part' => [[['title' => 'Una', 'two_minute_version' => 'abrir']], 'parts'],
    'missing 2-minute version' => [[['title' => 'Una', 'two_minute_version' => 'abrir'], ['title' => 'Dos', 'two_minute_version' => '  ']], 'parts.1.two_minute_version'],
    'missing title' => [[['title' => '', 'two_minute_version' => 'abrir'], ['title' => 'Dos', 'two_minute_version' => 'abrir']], 'parts.0.title'],
]);

test('moving a task re-hangs what it unlocked from the chosen task, keeping everything else', function () {
    $old = Item::factory()->for($this->plan)->create(['title' => 'Leer todo Control antes de empezar']);
    $target = Item::factory()->for($this->plan)->create(['title' => 'Mesa lista']);
    $dependent = Item::factory()->for($this->plan)->create(['title' => 'Primer ejercicio']);
    rtEdge($old, $dependent);

    $result = rtRetire($this->owner, $old, 'planificar sin práctica', RetirementDecision::Move, ['target' => $target->fresh()->key]);

    expect($dependent->fresh()->prerequisites()->pluck('items.id')->all())->toBe([$target->id])
        ->and($dependent->fresh()->deriveState())->toBe(ItemState::Locked)
        ->and($result->moved)->toBe(1)
        ->and($result->retirement->decision_payload['target'])->toMatchArray(['type' => 'item', 'id' => $target->id]);
});

test('moving a task cannot build a circle, and nothing changes', function () {
    $old = Item::factory()->for($this->plan)->create(['title' => 'Viejo']);
    $dependent = Item::factory()->for($this->plan)->create(['title' => 'Siguiente']);
    $later = Item::factory()->for($this->plan)->create(['title' => 'Después']);
    rtEdge($old, $dependent);
    rtEdge($dependent, $later);

    $errors = mosErrors(fn () => rtRetire($this->owner, $old, 'planificar sin práctica', RetirementDecision::Move, ['target' => $later->fresh()->key]));

    expect($errors['target'][0])->toContain('círculo')
        ->and($old->fresh()->retired_at)->toBeNull()
        ->and(Retirement::query()->count())->toBe(0)
        ->and(ItemDependency::query()->count())->toBe(2);
});

test('moving a task needs something to move and a valid target', function () {
    $leaf = Item::factory()->for($this->plan)->create();
    $other = Item::factory()->for($this->plan)->create();

    expect(mosErrors(fn () => rtRetire($this->owner, $leaf, 'planificar sin práctica', RetirementDecision::Move, ['target' => $other->fresh()->key])))->toHaveKey('decision');

    rtEdge($leaf, $other);
    $foreign = Item::factory()->create();

    expect(mosErrors(fn () => rtRetire($this->owner, $leaf, 'planificar sin práctica', RetirementDecision::Move, ['target' => $foreign->fresh()->key])))->toHaveKey('target')
        ->and(mosErrors(fn () => rtRetire($this->owner, $leaf, 'planificar sin práctica', RetirementDecision::Move, ['target' => $leaf->fresh()->key])))->toHaveKey('target')
        ->and(mosErrors(fn () => rtRetire($this->owner, $leaf, 'planificar sin práctica', RetirementDecision::Move, [])))->toHaveKey('target')
        ->and($leaf->fresh()->retired_at)->toBeNull();
});

test('retiring the active task releases it and closes its focus session', function () {
    $item = Item::factory()->for($this->plan)->create(['is_active' => true]);
    $session = FocusSession::query()->create(['item_id' => $item->id, 'started_at' => now()->subMinutes(10)]);

    $result = rtRetire($this->owner, $item, 'no era el momento todavía');

    $item = Item::withRetired()->findOrFail($item->id);

    expect($item->is_active)->toBeFalse()
        ->and($result->retirement->prior_state)->toBe('active')
        ->and($session->fresh()->ended_at)->not->toBeNull()
        ->and($session->fresh()->end_reason)->toBe('retired');
});

test('retiring the last open item of an active plan completes the plan', function () {
    Item::factory()->for($this->plan)->done()->create();
    $open = Item::factory()->for($this->plan)->create();

    rtRetire($this->owner, $open, 'ya no hace falta esto');

    expect($this->plan->fresh()->state)->toBe(PlanState::Done);
});

test('moving a plan\'s items to another plan of the same objective keeps their numbers', function () {
    $items = Item::factory()->for($this->plan)->count(3)->create();
    $target = Plan::factory()->for($this->objective)->create(['title' => 'Física II']);
    $numbers = $items->pluck('number')->all();

    $result = rtRetire($this->owner, $this->plan, 'no va así, lo reparto', RetirementDecision::Move, ['target' => $target->id]);

    expect(Plan::withRetired()->findOrFail($this->plan->id)->state)->toBe(PlanState::Retired)
        ->and(Plan::query()->find($this->plan->id))->toBeNull()
        ->and($result->moved)->toBe(3)
        ->and($result->retirement->prior_state)->toBe('active')
        ->and($result->retirement->kind)->toBe(RetirableKind::Plan)
        ->and(Item::query()->where('plan_id', $target->id)->orderBy('number')->pluck('number')->all())->toBe($numbers)
        ->and(Item::query()->whereKey($items->pluck('id'))->whereNull('retired_at')->count())->toBe(3);
});

test('a plan cannot move its items to a plan of another objective or to itself', function () {
    Item::factory()->for($this->plan)->create();
    $foreign = Plan::factory()->create();

    expect(mosErrors(fn () => rtRetire($this->owner, $this->plan, 'no va así, lo reparto', RetirementDecision::Move, ['target' => $foreign->id])))->toHaveKey('target')
        ->and(mosErrors(fn () => rtRetire($this->owner, $this->plan, 'no va así, lo reparto', RetirementDecision::Move, ['target' => $this->plan->id])))->toHaveKey('target')
        ->and($this->plan->fresh()->state)->toBe(PlanState::Active);
});

test('archiving a plan as-is retires its open items with the same reason', function () {
    $open = Item::factory()->for($this->plan)->count(2)->create();
    $alreadyRetired = Item::factory()->for($this->plan)->retired()->create();

    $result = rtRetire($this->owner, $this->plan, 'planificar sin práctica', RetirementDecision::ArchiveAsIs);

    $children = Retirement::query()->where('parent_id', $result->retirement->id)->get();

    expect($result->cascaded)->toBe(2)
        ->and($children)->toHaveCount(2)
        ->and($children->pluck('retirable_id')->sort()->values()->all())->toBe($open->pluck('id')->all())
        ->and($children->pluck('reason')->unique()->all())->toBe(['planificar sin práctica'])
        ->and($children->pluck('decision')->unique()->all())->toBe([RetirementDecision::ArchiveAsIs])
        ->and(Retirement::query()->where('retirable_id', $alreadyRetired->id)->exists())->toBeFalse()
        ->and(Item::query()->where('plan_id', $this->plan->id)->count())->toBe(0);
});

test('splitting an active plan creates active parts with a copy of its 5-point plan and control map, and hands its items to them', function () {
    $source = Plan::factory()->for($this->objective)->withControlPlan()->create(['title' => 'Semana 2']);
    $source->controlMapEntries()->create(['zone' => 'mine', 'text' => 'Escribir el spec', 'position' => 0]);
    [$one, $two] = Item::factory()->for($source)->count(2)->create()->all();

    $result = rtRetire($this->owner, $source, 'demasiado grande como plan', RetirementDecision::Split, [
        'parts' => [['title' => 'Semana 2a'], ['title' => 'Semana 2b']],
        'assignments' => [$two->id => 1],
    ]);

    [$a, $b] = $result->created->all();

    foreach ([$a, $b] as $part) {
        $part = $part->fresh(['controlPlan', 'controlMapEntries']);

        expect($part->state)->toBe(PlanState::Active)
            ->and($part->objective_id)->toBe($this->objective->id)
            ->and($part->controlPlan->isComplete())->toBeTrue()
            ->and($part->controlPlan->outcome)->toBe($source->controlPlan->outcome)
            ->and($part->controlPlan->risks)->toBe($source->controlPlan->risks)
            ->and($part->controlMapEntries->pluck('text')->all())->toBe(['Escribir el spec']);
    }

    expect($one->fresh()->plan_id)->toBe($a->id)
        ->and($two->fresh()->plan_id)->toBe($b->id)
        ->and($one->fresh()->number)->toBe($one->number)
        ->and($source->fresh()->controlPlan)->not->toBeNull();
});

test('splitting an active plan without a complete 5-point plan leaves the parts as drafts', function () {
    Item::factory()->for($this->plan)->create();

    $result = rtRetire($this->owner, $this->plan, 'demasiado grande como plan', RetirementDecision::Split, [
        'parts' => [['title' => 'Parte 1'], ['title' => 'Parte 2']],
    ]);

    expect(collect($result->created)->map(fn (Plan $plan) => $plan->fresh()->state)->unique()->all())->toBe([PlanState::Draft]);
});

test('archiving an objective retires its plans and items but never its habits', function () {
    $item = Item::factory()->for($this->plan)->create();
    $habit = Habit::factory()->for($this->objective->user)->create();

    $result = rtRetire($this->owner, $this->objective, 'ya no es prioridad este año', RetirementDecision::ArchiveAsIs);

    expect(Objective::withRetired()->findOrFail($this->objective->id)->state)->toBe(ObjectiveState::Retired)
        ->and($result->retirement->prior_state)->toBe('active')
        ->and($result->retirement->kind)->toBe(RetirableKind::Objective)
        ->and(Plan::withRetired()->findOrFail($this->plan->id)->state)->toBe(PlanState::Retired)
        ->and(Item::withRetired()->findOrFail($item->id)->retired_at)->not->toBeNull()
        ->and(Retirement::query()->where('parent_id', $result->retirement->id)->count())->toBe(1)
        ->and(Retirement::query()->count())->toBe(3)
        ->and($habit->fresh()->retired_at)->toBeNull();
});

test('moving an objective hands its plans to another active objective with new item numbers', function () {
    $target = Objective::factory()->for($this->objective->user)->withControlPlan()->create(['key' => 'DINERO']);
    Item::factory()->for(Plan::factory()->for($target))->create();
    $item = Item::factory()->for($this->plan)->create();

    rtRetire($this->owner, $this->objective, 'lo junto con el otro objetivo', RetirementDecision::Move, ['target' => 'DINERO']);

    $item = $item->fresh();

    expect($this->plan->fresh()->objective_id)->toBe($target->id)
        ->and($item->objective_id)->toBe($target->id)
        ->and($item->number)->toBe(2)
        ->and($target->fresh()->next_item_number)->toBe(3);
});

test('a closed objective and its content cannot be retired', function () {
    $closed = Objective::factory()->for($this->objective->user)->closed()->withControlPlan()->create();
    $item = Item::factory()->for(Plan::factory()->for($closed))->create();

    expect(mosErrors(fn () => rtRetire($this->owner, $item, 'ya no hace falta esto')))->toHaveKey('objective')
        ->and(mosErrors(fn () => rtRetire($this->owner, $closed, 'ya no hace falta esto')))->toHaveKey('objective');
});

test('another owner\'s element or an already retired one is not found', function () {
    $foreign = Item::factory()->create();
    $retired = Item::factory()->for($this->plan)->retired()->create();

    expect(fn () => rtRetire($this->owner, $foreign, 'ya no hace falta esto'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => rtRetire($this->owner, $retired, 'ya no hace falta esto'))->toThrow(ModelNotFoundException::class);
});

test('an AI actor cannot retire directly: it is a major operation', function () {
    $item = Item::factory()->for($this->plan)->create();

    expect(fn () => rtRetire(Actor::aiMcp($this->objective->user), $item, 'ya no hace falta esto'))
        ->toThrow(MajorOperationRequiresProposal::class)
        ->and($item->fresh()->retired_at)->toBeNull()
        ->and(Retirement::query()->count())->toBe(0);
});

test('a decision that does not apply to the kind is rejected', function () {
    $habit = Habit::factory()->for($this->objective->user)->create();

    expect(mosErrors(fn () => rtRetire(Actor::ownerWeb($habit->user), $habit, 'demasiado grande para arrancar', RetirementDecision::Split, ['parts' => [['title' => 'a'], ['title' => 'b']]])))
        ->toHaveKey('decision');
});

test('nothing is deleted: no delete route exists for objectives, plans, items or habits', function () {
    $deleteUris = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('DELETE', $route->methods(), true))
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => preg_match('#^(api/v1/)?(objectives|habits)(/\{[a-z]+\})?(/plans/\{plan\}|/items/\{item\})?$#', $uri) === 1);

    expect($deleteUris->all())->toBe([])
        ->and(User::factory()->create())->not->toBeNull();
});

test('splitting a draft plan keeps the parts as drafts', function () {
    $draft = Plan::factory()->for($this->objective)->create(['state' => PlanState::Draft]);
    Item::factory()->for($draft)->create();

    $result = rtRetire($this->owner, $draft, 'demasiado grande como plan', RetirementDecision::Split, [
        'parts' => [['title' => 'Parte 1'], ['title' => 'Parte 2']],
    ]);

    expect(collect($result->created)->map(fn (Plan $plan) => $plan->fresh()->state)->unique()->all())->toBe([PlanState::Draft]);
});

test('re-hanging dependents on an open task releases an active dependent that becomes locked', function () {
    $done = Item::factory()->for($this->plan)->done()->create();
    $open = Item::factory()->for($this->plan)->create();
    $active = Item::factory()->for($this->plan)->create(['is_active' => true]);
    rtEdge($done, $active);
    $session = FocusSession::query()->create(['item_id' => $active->id, 'started_at' => now()->subMinutes(5)]);

    rtRetire($this->owner, $done, 'ya no la necesito así', RetirementDecision::Move, ['target' => $open->fresh()->key]);

    expect($active->fresh()->is_active)->toBeFalse()
        ->and($active->fresh()->deriveState())->toBe(ItemState::Locked)
        ->and($session->fresh()->end_reason)->toBe('relocked');
});

test('a stale copy cannot retire the same element twice: the second request is refused cleanly', function () {
    $item = Item::factory()->for($this->plan)->create();
    $stale = Item::query()->findOrFail($item->id);

    rtRetire($this->owner, $item, 'primera petición llegó');

    expect(mosErrors(fn () => rtRetire($this->owner, $stale, 'segunda petición llegó'))['retirement'][0])->toBe('Esto ya está retirado.')
        ->and(Retirement::query()->whereNull('restored_at')->count())->toBe(1);
});

test('splitting a done plan derives each part from its items: all done is done, an empty part is draft', function () {
    $source = Plan::factory()->for($this->objective)->withControlPlan()->create(['state' => PlanState::Done]);
    [$one, $two] = Item::factory()->for($source)->done()->count(2)->create()->all();

    $result = rtRetire($this->owner, $source, 'ya cerrado, lo reorganizo', RetirementDecision::Split, [
        'parts' => [['title' => 'Con tareas'], ['title' => 'Vacía']],
    ]);

    [$withItems, $empty] = $result->created->all();

    expect($withItems->fresh()->state)->toBe(PlanState::Done)
        ->and($empty->fresh()->state)->toBe(PlanState::Draft)
        ->and($one->fresh()->plan_id)->toBe($withItems->id);
});

test('splitting a done plan whose item gets reopened later still yields an active part only with a complete 5-point plan', function () {
    $source = Plan::factory()->for($this->objective)->withControlPlan()->create(['state' => PlanState::Done]);
    Item::factory()->for($source)->done()->create();
    $open = Item::factory()->for($source)->create();

    $result = rtRetire($this->owner, $source, 'ya cerrado, lo reorganizo', RetirementDecision::Split, [
        'parts' => [['title' => 'Hecha'], ['title' => 'Abierta']],
        'assignments' => [$open->id => 1],
    ]);

    [$done, $active] = $result->created->all();

    expect($done->fresh()->state)->toBe(PlanState::Done)
        ->and($active->fresh()->state)->toBe(PlanState::Active);
});

test('an empty part of an active plan split is never active', function () {
    $source = Plan::factory()->for($this->objective)->withControlPlan()->create();
    Item::factory()->for($source)->create();

    $result = rtRetire($this->owner, $source, 'demasiado grande como plan', RetirementDecision::Split, [
        'parts' => [['title' => 'Con tarea'], ['title' => 'Vacía']],
    ]);

    [$withItem, $empty] = $result->created->all();

    expect($withItem->fresh()->state)->toBe(PlanState::Active)
        ->and($empty->fresh()->state)->toBe(PlanState::Draft);
});
