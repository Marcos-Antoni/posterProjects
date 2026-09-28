<?php

/*
| Phase 6 acceptance (independent tester) — spec `retirement` ("Retiring
| Requires A Written Reason And A Content Decision", "A Retired Element Can
| Be Restored"), `unlock-graph` ("Retired Items Do Not Block"), design D8:
| reason validation, every decision per element kind, atomicity under a
| forced failure mid-decision, cycles on move, dependents recomputed,
| cascade hide/restore, prior_state restoration, AI tier.
*/

use App\Actions\Retirement\RestoreElement;
use App\Actions\Retirement\RetireElement;
use App\Actions\Retirement\RetirementHandlers;
use App\Actions\Retirement\RetirementRecorder;
use App\Actions\Support\Actor;
use App\Actions\Support\MajorOperationRequiresProposal;
use App\Enums\ItemKind;
use App\Enums\ItemState;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Enums\RetirementDecision;
use App\Models\Habit;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Retirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

function p6Item(Plan $plan, string $title, array $attributes = []): Item
{
    return Item::factory()->for($plan)->create(['title' => $title, ...$attributes])->load('objective');
}

function p6Edge(Item $prerequisite, Item $dependent): void
{
    ItemDependency::query()->create(['prerequisite_id' => $prerequisite->id, 'dependent_id' => $dependent->id]);
}

function p6State(Item $item): ItemState
{
    return Item::withRetired()->withState()->whereKey($item->id)->firstOrFail()->state;
}

function p6Retire(Model $element, string $reason = 'ya no tiene sentido hacerlo', ?RetirementDecision $decision = null, array $payload = [], ?Actor $actor = null): mixed
{
    $owner = $element instanceof Habit ? $element->user : ($element instanceof Objective ? $element->user : $element->objective->user);

    return app(RetireElement::class)($actor ?? Actor::ownerWeb($owner), $element->fresh() ?? $element, $reason, $decision, $payload);
}

function p6Restore(Retirement $retirement, ?User $owner = null): Model
{
    return app(RestoreElement::class)(Actor::ownerWeb($owner ?? $retirement->user), $retirement->fresh());
}

/**
 * A snapshot of every row the retirement can touch, to prove "nothing changed".
 *
 * @return array<string, mixed>
 */
function p6Snapshot(): array
{
    return [
        'items' => Item::withRetired()->orderBy('id')->get(['id', 'objective_id', 'plan_id', 'number', 'position', 'retired_at', 'is_active', 'completed_at'])->toArray(),
        'plans' => Plan::withRetired()->orderBy('id')->get(['id', 'objective_id', 'state', 'position', 'title'])->toArray(),
        'objectives' => Objective::withRetired()->orderBy('id')->get(['id', 'state', 'next_item_number'])->toArray(),
        'edges' => ItemDependency::query()->orderBy('id')->get(['prerequisite_id', 'dependent_id'])->toArray(),
        'retirements' => Retirement::query()->count(),
    ];
}

/**
 * Make the Nth `record()` call blow up (a failure in the middle of a decision).
 */
function p6FailOnRecord(int $failingCall): void
{
    app()->bind(RetirementRecorder::class, fn ($app) => new class($app->make(RetirementHandlers::class), $failingCall) extends RetirementRecorder
    {
        private int $calls = 0;

        public function __construct(RetirementHandlers $handlers, private int $failingCall)
        {
            parent::__construct($handlers);
        }

        public function record(Model $element, string $reason, RetirementDecision $decision, ?array $payload = null, ?Retirement $parent = null): Retirement
        {
            if (++$this->calls === $this->failingCall) {
                throw new RuntimeException('forced failure mid-retirement');
            }

            return parent::record($element, $reason, $decision, $payload, $parent);
        }
    });
}

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->salud = Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'SALUD', 'title' => 'Correr 10K']);
    $this->dinero = Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'DINERO', 'title' => 'Ahorro']);
    $this->base = Plan::factory()->for($this->salud)->create(['title' => 'Base aeróbica']);
    $this->ritmo = Plan::factory()->for($this->salud)->create(['title' => 'Ritmo']);
});

// ---------------------------------------------------------------- reason

test('retiring without a reason is rejected with a Spanish message on the web and nothing changes', function (mixed $reason) {
    p6Item($this->base, 'Comprar zapatillas');
    $before = p6Snapshot();

    $response = $this->from('/objectives/SALUD/items/SALUD-1')
        ->post('/objectives/SALUD/items/SALUD-1/retire', ['reason' => $reason, 'decision' => 'archive_as_is']);

    $response->assertSessionHasErrors('reason');
    expect(session('errors')->first('reason'))->toContain('10 caracteres')
        ->and(p6Snapshot())->toBe($before);
})->with([
    'empty' => [''],
    'missing' => [null],
    'nine chars' => ['123456789'],
    'padded to 10 with spaces' => ['   corto   '],
    'nine multibyte chars' => ['ñáéíóúñáé'],
]);

test('a reason of exactly 10 characters (multibyte counted as characters) is accepted and stored trimmed', function () {
    $item = p6Item($this->base, 'Comprar zapatillas');

    $this->post('/objectives/SALUD/items/SALUD-1/retire', ['reason' => '  ñáéíóúñáéí  ', 'decision' => 'archive_as_is'])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect(Retirement::query()->sole()->reason)->toBe('ñáéíóúñáéí')
        ->and(Item::query()->find($item->id))->toBeNull();
});

test('the action itself enforces the reason, so no surface can skip it', function () {
    $item = p6Item($this->base, 'Comprar zapatillas');

    $errors = mosErrors(fn () => p6Retire($item, 'corto'));

    expect($errors)->toHaveKey('reason')
        ->and(Item::query()->find($item->id))->not->toBeNull();
});

// ---------------------------------------------------------------- defaults

test('a leaf item without dependents defaults to archive as-is; an item with dependents must choose', function () {
    $leaf = p6Item($this->base, 'Hoja');
    $a = p6Item($this->base, 'A');
    $b = p6Item($this->base, 'B');
    p6Edge($a, $b);

    $result = p6Retire($leaf);
    expect($result->retirement->decision)->toBe(RetirementDecision::ArchiveAsIs);

    $errors = mosErrors(fn () => p6Retire($a));
    expect($errors)->toHaveKey('decision')
        ->and(Item::query()->find($a->id))->not->toBeNull();
});

test('a plan or objective with content must choose; an empty one defaults to archive as-is', function () {
    p6Item($this->base, 'A');

    expect(mosErrors(fn () => p6Retire($this->base)))->toHaveKey('decision')
        ->and(mosErrors(fn () => p6Retire($this->salud)))->toHaveKey('decision');

    $empty = Plan::factory()->for($this->salud)->create();
    expect(p6Retire($empty)->retirement->decision)->toBe(RetirementDecision::ArchiveAsIs);

    $emptyObjective = Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'VACIO']);
    expect(p6Retire($emptyObjective)->retirement->decision)->toBe(RetirementDecision::ArchiveAsIs);
});

test('an unknown decision value is rejected by the web request', function () {
    p6Item($this->base, 'A');

    $this->post('/objectives/SALUD/items/SALUD-1/retire', ['reason' => 'ya no tiene sentido', 'decision' => 'delete'])
        ->assertSessionHasErrors('decision');

    expect(Retirement::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- items

test('archive as-is on the only prerequisite unlocks the dependent (unlock-graph scenario)', function () {
    $a = p6Item($this->base, 'A');
    $b = p6Item($this->base, 'B');
    p6Edge($a, $b);
    expect(p6State($b))->toBe(ItemState::Locked);

    $result = p6Retire($a, 'ya no hace falta esto', RetirementDecision::ArchiveAsIs);

    expect(p6State($b))->toBe(ItemState::Available)
        ->and($result->unlocked->pluck('id')->all())->toBe([$b->id])
        ->and(p6State($a))->toBe(ItemState::Retired);
});

test('the web flashes the unlocked dependents after retiring', function () {
    $a = p6Item($this->base, 'A');
    $b = p6Item($this->base, 'Correr 5 km');
    p6Edge($a, $b);

    $this->post('/objectives/SALUD/items/SALUD-1/retire', ['reason' => 'ya no hace falta esto', 'decision' => 'archive_as_is'])
        ->assertSessionHas('unlocked', [['key' => 'SALUD-2', 'title' => 'Correr 5 km']]);
});

test('a dependent that still has another open prerequisite stays locked and is not reported as unlocked', function () {
    $a = p6Item($this->base, 'A');
    $x = p6Item($this->base, 'X');
    $b = p6Item($this->base, 'B');
    p6Edge($a, $b);
    p6Edge($x, $b);

    $result = p6Retire($a, 'ya no hace falta esto', RetirementDecision::ArchiveAsIs);

    expect(p6State($b))->toBe(ItemState::Locked)
        ->and($result->unlocked)->toBeEmpty();
});

test('splitting replaces a task with smaller ones in the same plan inheriting prerequisites and dependents (spec scenario)', function () {
    $pre = p6Item($this->base, 'Pre', ['completed_at' => now()]);
    $big = p6Item($this->base, 'Grande');
    $after = p6Item($this->base, 'Después');
    p6Edge($pre, $big);
    p6Edge($big, $after);

    $result = p6Retire($big, 'demasiado grande', RetirementDecision::Split, ['parts' => [
        ['title' => 'Parte uno', 'two_minute_version' => 'abrir el doc'],
        ['title' => 'Parte dos', 'two_minute_version' => 'escribir una línea'],
    ]]);

    $parts = Item::query()->whereIn('title', ['Parte uno', 'Parte dos'])->orderBy('number')->get();

    expect($parts)->toHaveCount(2)
        ->and(Item::query()->find($big->id))->toBeNull()
        ->and($result->created)->toHaveCount(2);

    foreach ($parts as $part) {
        expect($part->plan_id)->toBe($this->base->id)
            ->and($part->objective_id)->toBe($this->salud->id)
            ->and($part->kind)->toBe(ItemKind::Task)
            ->and($part->prerequisites()->pluck('items.id')->all())->toBe([$pre->id])
            ->and($part->dependents()->pluck('items.id')->all())->toBe([$after->id])
            ->and(p6State($part))->toBe(ItemState::Available);
    }

    // Numbers are fresh (never reused): 4 and 5.
    expect($parts->pluck('number')->all())->toBe([4, 5])
        // The dependent now waits on both parts.
        ->and(p6State($after))->toBe(ItemState::Locked);
});

test('split validation: fewer than two parts or a part without 2-minute version is rejected and nothing changes', function (array $parts) {
    p6Item($this->base, 'Grande');
    $before = p6Snapshot();

    $errors = mosErrors(fn () => p6Retire(Item::query()->firstOrFail(), 'demasiado grande', RetirementDecision::Split, ['parts' => $parts]));

    expect($errors)->not->toBeEmpty()
        ->and(p6Snapshot())->toBe($before);
})->with([
    'none' => [[]],
    'one' => [[['title' => 'Una', 'two_minute_version' => 'abrir']]],
    'missing two-minute' => [[['title' => 'Una', 'two_minute_version' => 'abrir'], ['title' => 'Dos', 'two_minute_version' => '  ']]],
    'missing title' => [[['title' => '', 'two_minute_version' => 'abrir'], ['title' => 'Dos', 'two_minute_version' => 'abrir']]],
]);

test('moving an item re-hangs what it unlocked from the chosen target', function () {
    $a = p6Item($this->base, 'A');
    $b = p6Item($this->base, 'B');
    $target = p6Item($this->ritmo, 'Nuevo prerequisito');
    p6Edge($a, $b);

    $result = p6Retire($a, 'lo hago de otra forma', RetirementDecision::Move, ['target' => 'SALUD-3']);

    expect(ItemDependency::query()->where('prerequisite_id', $target->id)->where('dependent_id', $b->id)->exists())->toBeTrue()
        ->and(p6State($b))->toBe(ItemState::Locked)
        ->and($result->moved)->toBe(1)
        ->and($result->unlocked)->toBeEmpty();
});

test('moving onto a done target unlocks the dependents', function () {
    $a = p6Item($this->base, 'A');
    $b = p6Item($this->base, 'B');
    p6Item($this->ritmo, 'Ya hecha', ['completed_at' => now()]);
    p6Edge($a, $b);

    $result = p6Retire($a, 'lo hago de otra forma', RetirementDecision::Move, ['target' => 'SALUD-3']);

    expect(p6State($b))->toBe(ItemState::Available)
        ->and($result->unlocked->pluck('id')->all())->toBe([$b->id]);
});

test('a move that would close a cycle is rejected atomically, even after other dependents were re-hung', function () {
    // X unlocks D1 and D2; D2 unlocks T. Moving X's dependents onto T:
    // T -> D1 is fine (added first), T -> D2 closes D2 -> T -> D2.
    $x = p6Item($this->base, 'X');
    $d1 = p6Item($this->base, 'D1');
    $d2 = p6Item($this->base, 'D2');
    $t = p6Item($this->base, 'T');
    p6Edge($x, $d1);
    p6Edge($x, $d2);
    p6Edge($d2, $t);
    $before = p6Snapshot();

    $errors = mosErrors(fn () => p6Retire($x, 'lo hago de otra forma', RetirementDecision::Move, ['target' => 'SALUD-4']));

    expect($errors)->toHaveKey('target')
        ->and(implode(' ', $errors['target']))->toContain('círculo')
        ->and(p6Snapshot())->toBe($before);
});

test('a move whose target is a transitive descendant is rejected (longer cycle)', function () {
    $x = p6Item($this->base, 'X');
    $d = p6Item($this->base, 'D');
    $m = p6Item($this->base, 'M');
    $t = p6Item($this->dinero->plans()->first() ?? Plan::factory()->for($this->dinero)->create(), 'T lejos');
    p6Edge($x, $d);
    p6Edge($d, $m);
    p6Edge($m, $t);
    $before = p6Snapshot();

    expect(mosErrors(fn () => p6Retire($x, 'lo hago de otra forma', RetirementDecision::Move, ['target' => $t->fresh()->load('objective')->key])))->toHaveKey('target')
        ->and(p6Snapshot())->toBe($before);
});

test('move rejects itself, its own dependent, a retired, foreign or unknown target', function (Closure $targetKey) {
    $x = p6Item($this->base, 'X');
    $d = p6Item($this->base, 'D');
    p6Edge($x, $d);
    p6Item($this->base, 'Retirada', ['retired_at' => now()]);
    $stranger = Objective::factory()->for(User::factory())->withControlPlan()->create(['key' => 'AJENO']);
    p6Item(Plan::factory()->for($stranger)->create(), 'Ajena');
    $before = p6Snapshot();

    expect(mosErrors(fn () => p6Retire($x, 'lo hago de otra forma', RetirementDecision::Move, ['target' => $targetKey()])))->toHaveKey('target')
        ->and(p6Snapshot())->toBe($before);
})->with([
    'itself' => [fn () => 'SALUD-1'],
    'its dependent' => [fn () => 'SALUD-2'],
    'retired' => [fn () => 'SALUD-3'],
    'another owner' => [fn () => 'AJENO-1'],
    'unknown' => [fn () => 'SALUD-99'],
    'malformed' => [fn () => 'nope'],
]);

test('an active item leaves Now when retired and its open focus session is closed', function () {
    $item = p6Item($this->base, 'Activa', ['is_active' => true]);
    $item->focusSessions()->create(['started_at' => now()->subMinutes(5)]);

    p6Retire($item);

    $fresh = Item::withRetired()->findOrFail($item->id);
    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->focusSessions()->whereNull('ended_at')->count())->toBe(0);
});

test('retiring the last open item of an active plan makes it done; restoring it reopens the plan', function () {
    p6Item($this->base, 'Hecha', ['completed_at' => now()]);
    $open = p6Item($this->base, 'Abierta');
    $this->base->update(['state' => PlanState::Active]);

    p6Retire($open);
    expect($this->base->fresh()->state)->toBe(PlanState::Done);

    p6Restore(Retirement::query()->sole());
    expect($this->base->fresh()->state)->toBe(PlanState::Active);
});

// ---------------------------------------------------------------- plans

test('moving a plan\'s three tasks to another plan keeps their numbers (spec scenario)', function () {
    $tasks = collect(['Uno', 'Dos', 'Tres'])->map(fn (string $title) => p6Item($this->base, $title));
    $numbers = $tasks->pluck('number')->all();
    p6Edge($tasks[0], $tasks[1]);

    $result = p6Retire($this->base, 'lo reparto en otro plan', RetirementDecision::Move, ['target' => $this->ritmo->id]);

    $moved = Item::query()->whereIn('id', $tasks->pluck('id'))->orderBy('number')->get();
    expect($moved->pluck('plan_id')->unique()->all())->toBe([$this->ritmo->id])
        ->and($moved->pluck('number')->all())->toBe($numbers)
        ->and(Plan::query()->find($this->base->id))->toBeNull()
        ->and(Plan::withRetired()->findOrFail($this->base->id)->state)->toBe(PlanState::Retired)
        ->and(ItemDependency::query()->count())->toBe(1)
        ->and($result->moved)->toBe(3);
});

test('a plan cannot move into another objective\'s plan, a retired plan or itself', function (Closure $target) {
    p6Item($this->base, 'Uno');
    $foreign = Plan::factory()->for($this->dinero)->create();
    $retired = Plan::factory()->for($this->salud)->create(['state' => PlanState::Retired]);
    $targets = ['foreign' => $foreign->id, 'retired' => $retired->id, 'self' => $this->base->id, 'unknown' => 999999];
    $before = p6Snapshot();

    expect(mosErrors(fn () => p6Retire($this->base, 'lo reparto en otro plan', RetirementDecision::Move, ['target' => $targets[$target()]])))->toHaveKey('target')
        ->and(p6Snapshot())->toBe($before);
})->with([
    'another objective' => [fn () => 'foreign'],
    'retired plan' => [fn () => 'retired'],
    'itself' => [fn () => 'self'],
    'unknown' => [fn () => 'unknown'],
]);

test('archiving a plan as-is retires its open items with the same reason and hides them; restoring brings all back', function () {
    $one = p6Item($this->base, 'Uno');
    $two = p6Item($this->base, 'Dos', ['completed_at' => now()]);
    $earlier = p6Item($this->base, 'Retirada antes');
    p6Retire($earlier, 'ya no hace falta esto');
    $outside = p6Item($this->ritmo, 'Fuera del plan');
    p6Edge($one, $outside);

    $result = p6Retire($this->base, 'el plan no sirve más', RetirementDecision::ArchiveAsIs);

    $planRetirement = $result->retirement;
    expect($result->cascaded)->toBe(2)
        ->and($planRetirement->children()->pluck('reason')->all())->toBe(['el plan no sirve más', 'el plan no sirve más'])
        ->and(Item::query()->whereIn('id', [$one->id, $two->id])->count())->toBe(0)
        ->and(p6State($outside))->toBe(ItemState::Available)
        ->and($result->unlocked->pluck('id')->all())->toBe([$outside->id]);

    // A child cannot come back alone while its plan is retired.
    $child = $planRetirement->children()->where('retirable_id', $one->id)->sole();
    expect(mosErrors(fn () => p6Restore($child)))->toHaveKey('retirement')
        ->and(implode(' ', mosErrors(fn () => p6Restore($child))['retirement']))->toContain('Primero devolvé su plan');

    p6Restore($planRetirement);

    expect(Plan::query()->find($this->base->id))->not->toBeNull()
        ->and(Item::query()->whereIn('id', [$one->id, $two->id])->count())->toBe(2)
        ->and(p6State($two))->toBe(ItemState::Done)
        ->and(p6State($outside))->toBe(ItemState::Locked)
        // The item retired on its own before stays retired: it has its own history.
        ->and(Item::query()->find($earlier->id))->toBeNull()
        ->and(Retirement::query()->count())->toBe(4)
        ->and(Retirement::query()->whereNull('restored_at')->count())->toBe(1);
});

test('splitting a plan creates the new plans and hands its items over by assignment, keeping numbers', function () {
    $one = p6Item($this->base, 'Uno');
    $two = p6Item($this->base, 'Dos');

    $result = p6Retire($this->base, 'mezclaba dos cosas distintas', RetirementDecision::Split, [
        'parts' => [['title' => 'Parte A'], ['title' => 'Parte B']],
        'assignments' => [$two->id => 1],
    ]);

    [$a, $b] = $result->created->all();
    expect($a->title)->toBe('Parte A')->and($b->title)->toBe('Parte B')
        ->and($one->fresh()->plan_id)->toBe($a->id)
        ->and($two->fresh()->plan_id)->toBe($b->id)
        ->and($one->fresh()->number)->toBe($one->number)
        ->and(Plan::query()->find($this->base->id))->toBeNull();
});

// ---------------------------------------------------------------- objectives

test('archiving an objective as-is cascades to its plans and items; restoring brings back the whole tree with prior states', function () {
    $draft = Plan::factory()->for($this->salud)->draft()->create();
    $this->base->update(['state' => PlanState::Active]);
    $done = p6Item($this->base, 'Hecha', ['completed_at' => now()]);
    $open = p6Item($this->ritmo, 'Abierta');
    $nextNumber = $this->salud->fresh()->next_item_number;

    $result = p6Retire($this->salud, 'cambié de prioridad este año', RetirementDecision::ArchiveAsIs);

    expect(Objective::query()->find($this->salud->id))->toBeNull()
        ->and(Plan::query()->where('objective_id', $this->salud->id)->count())->toBe(0)
        ->and(Item::query()->where('objective_id', $this->salud->id)->count())->toBe(0)
        ->and($result->cascaded)->toBe(3 + 2);

    // Nothing inside can come back alone.
    $planChild = $result->retirement->children()->where('retirable_type', 'plan')->where('retirable_id', $this->ritmo->id)->sole();
    expect(implode(' ', mosErrors(fn () => p6Restore($planChild))['retirement']))->toContain('Primero devolvé su objetivo');

    p6Restore($result->retirement);

    $objective = Objective::query()->findOrFail($this->salud->id);
    expect($objective->state)->toBe(ObjectiveState::Active)
        ->and(Plan::query()->findOrFail($draft->id)->state)->toBe(PlanState::Draft)
        ->and(Plan::query()->findOrFail($this->base->id)->state)->toBe(PlanState::Done)
        ->and(p6State($done))->toBe(ItemState::Done)
        ->and(p6State($open))->toBe(ItemState::Available)
        ->and($objective->next_item_number)->toBe($nextNumber)
        ->and(Retirement::query()->whereNull('restored_at')->count())->toBe(0);
});

test('moving an objective hands its plans to another active objective with fresh numbers; old numbers are not reused', function () {
    $one = p6Item($this->base, 'Uno');
    $two = p6Item($this->ritmo, 'Dos');
    p6Edge($one, $two);
    $dineroPlan = Plan::factory()->for($this->dinero)->create();
    p6Item($dineroPlan, 'Ya en dinero');

    p6Retire($this->salud, 'lo junto con el ahorro', RetirementDecision::Move, ['target' => 'DINERO']);

    $one->refresh();
    $two->refresh();
    expect($one->objective_id)->toBe($this->dinero->id)
        ->and($two->objective_id)->toBe($this->dinero->id)
        ->and([$one->number, $two->number])->toBe([2, 3])
        ->and($this->base->fresh()->objective_id)->toBe($this->dinero->id)
        ->and(ItemDependency::query()->where('prerequisite_id', $one->id)->where('dependent_id', $two->id)->exists())->toBeTrue()
        ->and(Objective::withRetired()->findOrFail($this->salud->id)->state)->toBe(ObjectiveState::Retired);

    $this->get('/objectives/DINERO/items/DINERO-2')->assertOk();
    $this->get('/objectives/SALUD/items/SALUD-1')->assertNotFound();
});

test('an objective cannot move into itself, a closed, retired or foreign objective', function (Closure $key) {
    p6Item($this->base, 'Uno');
    Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'CERRADO', 'state' => ObjectiveState::Closed]);
    Objective::factory()->for($this->owner)->withControlPlan()->retired()->create(['key' => 'VIEJO']);
    Objective::factory()->for(User::factory())->withControlPlan()->create(['key' => 'AJENO']);
    $before = p6Snapshot();

    expect(mosErrors(fn () => p6Retire($this->salud, 'lo junto con otro objetivo', RetirementDecision::Move, ['target' => $key()])))->toHaveKey('target')
        ->and(p6Snapshot())->toBe($before);
})->with([
    'itself' => [fn () => 'SALUD'],
    'closed' => [fn () => 'CERRADO'],
    'retired' => [fn () => 'VIEJO'],
    'foreign' => [fn () => 'AJENO'],
]);

test('a closed objective cannot be retired and its items cannot be retired either', function () {
    p6Item($this->base, 'Uno');
    $this->salud->update(['state' => ObjectiveState::Closed, 'closed_at' => now()]);

    expect(mosErrors(fn () => p6Retire($this->salud, 'ya lo terminé igual', RetirementDecision::ArchiveAsIs)))->not->toBeEmpty()
        ->and(mosErrors(fn () => p6Retire(Item::query()->firstOrFail(), 'ya lo terminé igual')))->not->toBeEmpty()
        ->and(Retirement::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- atomicity

test('a failure mid-split rolls back the created items and the burnt numbers', function () {
    $big = p6Item($this->base, 'Grande');
    $before = p6Snapshot();
    p6FailOnRecord(1);

    expect(fn () => p6Retire($big, 'demasiado grande', RetirementDecision::Split, ['parts' => [
        ['title' => 'Uno', 'two_minute_version' => 'abrir'],
        ['title' => 'Dos', 'two_minute_version' => 'abrir'],
    ]]))->toThrow(RuntimeException::class);

    expect(p6Snapshot())->toBe($before);
});

test('a failure mid-move of a plan rolls back the items already handed over', function () {
    p6Item($this->base, 'Uno');
    p6Item($this->base, 'Dos');
    $before = p6Snapshot();
    p6FailOnRecord(1);

    expect(fn () => p6Retire($this->base, 'lo reparto en otro plan', RetirementDecision::Move, ['target' => $this->ritmo->id]))
        ->toThrow(RuntimeException::class);

    expect(p6Snapshot())->toBe($before);
});

test('a failure in the middle of a plan cascade leaves no item retired', function () {
    p6Item($this->base, 'Uno');
    p6Item($this->base, 'Dos');
    p6Item($this->base, 'Tres');
    $before = p6Snapshot();
    // 1 = plan row, 2 = first item, 3 = second item -> fails after one item was marked.
    p6FailOnRecord(3);

    expect(fn () => p6Retire($this->base, 'el plan no sirve más', RetirementDecision::ArchiveAsIs))->toThrow(RuntimeException::class);

    expect(p6Snapshot())->toBe($before);
});

test('a failure mid-move of an objective rolls back the renumbering and the moved plans', function () {
    p6Item($this->base, 'Uno');
    p6Item($this->ritmo, 'Dos');
    $before = p6Snapshot();
    p6FailOnRecord(1);

    expect(fn () => p6Retire($this->salud, 'lo junto con el ahorro', RetirementDecision::Move, ['target' => 'DINERO']))
        ->toThrow(RuntimeException::class);

    expect(p6Snapshot())->toBe($before);
});

test('a failure mid-move of an item rolls back the edges already re-hung', function () {
    $x = p6Item($this->base, 'X');
    $d1 = p6Item($this->base, 'D1');
    $d2 = p6Item($this->base, 'D2');
    p6Item($this->base, 'T');
    p6Edge($x, $d1);
    p6Edge($x, $d2);
    $before = p6Snapshot();
    p6FailOnRecord(1);

    expect(fn () => p6Retire($x, 'lo hago de otra forma', RetirementDecision::Move, ['target' => 'SALUD-4']))->toThrow(RuntimeException::class);

    expect(p6Snapshot())->toBe($before);
});

// ---------------------------------------------------------------- restore

test('restore keeps the retirement row as history and a second retirement adds a second row', function () {
    $item = p6Item($this->base, 'Uno');

    p6Retire($item, 'primera vez que la saco');
    p6Restore(Retirement::query()->sole());
    p6Retire($item, 'segunda vez que la saco');

    $rows = Retirement::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->restored_at)->not->toBeNull()
        ->and($rows[1]->restored_at)->toBeNull()
        ->and($item->retirements()->count())->toBe(2);
});

test('restoring recomputes dependents: the restored open prerequisite locks its dependent again', function () {
    $a = p6Item($this->base, 'A');
    $b = p6Item($this->base, 'B');
    p6Edge($a, $b);

    p6Retire($a, 'ya no hace falta esto', RetirementDecision::ArchiveAsIs);
    expect(p6State($b))->toBe(ItemState::Available);

    p6Restore(Retirement::query()->sole());
    expect(p6State($a))->toBe(ItemState::Available)
        ->and(p6State($b))->toBe(ItemState::Locked);
});

test('a done item comes back done; a habit comes back accepting entries with history untouched', function () {
    $done = p6Item($this->base, 'Hecha', ['completed_at' => now()]);
    $habit = Habit::factory()->for($this->owner)->create();
    $habit->recordEntry(1);
    $entries = $habit->entries()->count();

    p6Retire($done);
    p6Retire($habit, 'ya no me sirve este hábito');

    foreach (Retirement::query()->get() as $retirement) {
        p6Restore($retirement);
    }

    expect(p6State($done))->toBe(ItemState::Done)
        ->and(Habit::query()->findOrFail($habit->id)->retired_at)->toBeNull()
        ->and($habit->entries()->count())->toBe($entries);

    $this->post("/habits/{$habit->id}/entries")->assertSessionHasNoErrors();
    expect($habit->entries()->count())->toBe($entries + 1);
});

test('restoring someone else\'s retirement is not found; restoring twice is refused', function () {
    $item = p6Item($this->base, 'Uno');
    p6Retire($item);
    $retirement = Retirement::query()->sole();

    $this->actingAs(User::factory()->create())->post("/retired/{$retirement->id}/restore")->assertNotFound();
    expect(Item::query()->find($item->id))->toBeNull();

    $this->actingAs($this->owner)->post("/retired/{$retirement->id}/restore")->assertSessionHasNoErrors();
    $this->post("/retired/{$retirement->id}/restore")->assertSessionHasErrors('retirement');
});

// ---------------------------------------------------------------- ownership & AI tier

test('another user cannot retire my item, plan, objective or habit through the web (404, nothing changes)', function () {
    p6Item($this->base, 'Uno');
    $habit = Habit::factory()->for($this->owner)->create();
    $body = ['reason' => 'quiero borrarte esto', 'decision' => 'archive_as_is'];

    $this->actingAs(User::factory()->create());
    $this->post('/objectives/SALUD/items/SALUD-1/retire', $body)->assertNotFound();
    $this->post("/objectives/SALUD/plans/{$this->base->id}/retire", $body)->assertNotFound();
    $this->post('/objectives/SALUD/retire', $body)->assertNotFound();
    $this->post("/habits/{$habit->id}/retire", $body)->assertNotFound();
    $this->get("/habits/{$habit->id}/retire")->assertNotFound();

    expect(Retirement::query()->count())->toBe(0);
});

test('a plan of another objective cannot be retired through a mismatched URL', function () {
    $dineroPlan = Plan::factory()->for($this->dinero)->create();

    $this->post("/objectives/SALUD/plans/{$dineroPlan->id}/retire", ['reason' => 'mal url a propósito', 'decision' => 'archive_as_is'])->assertNotFound();

    expect(Retirement::query()->count())->toBe(0);
});

test('guests are redirected to login on every retirement route', function () {
    auth()->logout();

    $this->get('/retired')->assertRedirect('/login');
    $this->post('/objectives/SALUD/items/SALUD-1/retire')->assertRedirect('/login');
    $this->post('/retired/1/restore')->assertRedirect('/login');
});

test('the AI cannot retire or restore directly: major operation, nothing changes', function () {
    $item = p6Item($this->base, 'Uno');

    expect(fn () => p6Retire($item, 'la IA quiere sacarla', null, [], Actor::aiMcp($this->owner)))
        ->toThrow(MajorOperationRequiresProposal::class);
    expect(Item::query()->find($item->id))->not->toBeNull()
        ->and(Retirement::query()->count())->toBe(0);

    p6Retire($item, 'la saco yo mismo');
    $retirement = Retirement::query()->sole();

    expect(fn () => app(RestoreElement::class)(Actor::aiMcp($this->owner), $retirement))->toThrow(MajorOperationRequiresProposal::class);
    expect(Item::query()->find($item->id))->toBeNull();
});

test('an already retired element cannot be retired again through the web (hidden, 404)', function () {
    $item = p6Item($this->base, 'Uno');
    p6Retire($item);

    $this->post('/objectives/SALUD/items/SALUD-1/retire', ['reason' => 'segunda vez seguida', 'decision' => 'archive_as_is'])->assertNotFound();
    expect(Retirement::query()->count())->toBe(1);
});

test('a stale in-memory copy cannot retire the same element twice (race between two requests)', function () {
    $item = p6Item($this->base, 'Uno');
    $stale = Item::query()->findOrFail($item->id)->load('objective');

    app(RetireElement::class)(Actor::ownerWeb($this->owner), $item, 'primera petición llegó', null);

    $errors = mosErrors(fn () => app(RetireElement::class)(Actor::ownerWeb($this->owner), $stale, 'segunda petición llegó', null));
    expect($errors)->toHaveKey('retirement');

    expect(Retirement::query()->where('retirable_id', $item->id)->whereNull('restored_at')->count())->toBe(1);
});
