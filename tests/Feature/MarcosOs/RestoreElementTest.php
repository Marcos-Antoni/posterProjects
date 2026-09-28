<?php

use App\Actions\Retirement\RestoreElement;
use App\Actions\Retirement\RetireElement;
use App\Actions\Support\Actor;
use App\Actions\Support\MajorOperationRequiresProposal;
use App\Enums\ItemState;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Enums\RetirementDecision;
use App\Models\FocusSession;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Retirement;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
| Task 6.3 — restore (retirement spec "A Retired Element Can Be Restored"):
| back to its previous non-retired state, only when its parent is not retired,
| the retirement row kept as history, dependency states recomputed.
*/

beforeEach(function () {
    $this->objective = Objective::factory()->withControlPlan()->create(['key' => 'SALUD', 'title' => 'Aprobar finales']);
    $this->plan = Plan::factory()->for($this->objective)->create(['title' => 'Repaso general']);
    $this->owner = Actor::ownerWeb($this->objective->user);
    $this->retire = fn ($element, string $reason = 'ya no hace falta esto', ?RetirementDecision $decision = null, array $payload = []) => app(RetireElement::class)($this->owner, $element, $reason, $decision, $payload);
    $this->restore = fn (Retirement $retirement, ?Actor $actor = null) => app(RestoreElement::class)($actor ?? $this->owner, $retirement);
});

test('restoring a task brings it back as it was and keeps the retirement as history', function () {
    $item = Item::factory()->for($this->plan)->create();
    $retirement = ($this->retire)($item)->retirement;

    $restored = ($this->restore)($retirement);

    expect($restored)->toBeInstanceOf(Item::class)
        ->and(Item::query()->find($item->id))->not->toBeNull()
        ->and($item->fresh()->deriveState())->toBe(ItemState::Available)
        ->and($retirement->fresh()->restored_at)->not->toBeNull()
        ->and($retirement->fresh()->reason)->toBe('ya no hace falta esto')
        ->and(Retirement::query()->count())->toBe(1);

    // Retiring it again writes a second history row.
    ($this->retire)($item->fresh(), 'otra vez no, sigue sin servir');

    expect(Retirement::query()->where('retirable_id', $item->id)->count())->toBe(2);
});

test('a done task is restored done, and restoring recomputes dependents', function () {
    $a = Item::factory()->for($this->plan)->create();
    $b = Item::factory()->for($this->plan)->create();
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);
    $done = Item::factory()->for($this->plan)->done()->create();

    $retirement = ($this->retire)($a, 'ya no hace falta esto', RetirementDecision::ArchiveAsIs)->retirement;
    expect($b->fresh()->deriveState())->toBe(ItemState::Available);

    ($this->restore)($retirement);
    expect($b->fresh()->deriveState())->toBe(ItemState::Locked);

    ($this->restore)(($this->retire)($done)->retirement);
    expect($done->fresh()->deriveState())->toBe(ItemState::Done);
});

test('an active task comes back available, never silently active', function () {
    $item = Item::factory()->for($this->plan)->create(['is_active' => true]);

    ($this->restore)(($this->retire)($item)->retirement);

    expect($item->fresh()->is_active)->toBeFalse()
        ->and($item->fresh()->deriveState())->toBe(ItemState::Available);
});

test('restoring a task whose plan is retired is refused, asking to restore the plan first', function () {
    $item = Item::factory()->for($this->plan)->create();
    ($this->retire)($this->plan, 'planificar sin práctica', RetirementDecision::ArchiveAsIs);
    $childRetirement = Retirement::query()->where('retirable_type', 'item')->where('retirable_id', $item->id)->firstOrFail();

    $errors = mosErrors(fn () => ($this->restore)($childRetirement));

    expect($errors['retirement'][0])->toBe('Primero devolvé su plan, “Repaso general”.')
        ->and(Item::query()->find($item->id))->toBeNull()
        ->and($childRetirement->fresh()->restored_at)->toBeNull();
});

test('restoring a plan brings back the items retired with it, not the ones retired before', function () {
    $withPlan = Item::factory()->for($this->plan)->count(2)->create();
    $before = Item::factory()->for($this->plan)->create();
    ($this->retire)($before, 'retirada por su cuenta antes');
    $retirement = ($this->retire)($this->plan, 'planificar sin práctica', RetirementDecision::ArchiveAsIs)->retirement;

    ($this->restore)($retirement);

    expect($this->plan->fresh()->state)->toBe(PlanState::Active)
        ->and(Item::query()->where('plan_id', $this->plan->id)->pluck('id')->sort()->values()->all())->toBe($withPlan->pluck('id')->all())
        ->and(Retirement::query()->where('parent_id', $retirement->id)->whereNull('restored_at')->count())->toBe(0)
        ->and(Retirement::query()->whereNull('restored_at')->count())->toBe(1);
});

test('restoring a plan whose objective is retired is refused', function () {
    ($this->retire)($this->objective, 'ya no es prioridad este año', RetirementDecision::ArchiveAsIs);
    $planRetirement = Retirement::query()->where('retirable_type', 'plan')->firstOrFail();

    expect(mosErrors(fn () => ($this->restore)($planRetirement))['retirement'][0])
        ->toBe('Primero devolvé su objetivo, “Aprobar finales”.');
});

test('restoring an objective brings its plans and items back and returns it to navigation', function () {
    $item = Item::factory()->for($this->plan)->create();
    $retirement = ($this->retire)($this->objective, 'ya no es prioridad este año', RetirementDecision::ArchiveAsIs)->retirement;

    ($this->restore)($retirement);

    expect($this->objective->fresh()->state)->toBe(ObjectiveState::Active)
        ->and($this->plan->fresh()->state)->toBe(PlanState::Active)
        ->and(Item::query()->find($item->id))->not->toBeNull()
        ->and(Objective::query()->active()->pluck('key')->all())->toBe(['SALUD']);
});

test('restoring a split original keeps the parts in the map', function () {
    $big = Item::factory()->for($this->plan)->create();
    $result = ($this->retire)($big, 'demasiado grande, no arrancaba', RetirementDecision::Split, ['parts' => [
        ['title' => 'Parte uno', 'two_minute_version' => 'abrir'],
        ['title' => 'Parte dos', 'two_minute_version' => 'abrir'],
    ]]);

    ($this->restore)($result->retirement);

    expect(Item::query()->whereIn('title', ['Parte uno', 'Parte dos'])->count())->toBe(2)
        ->and(Item::query()->find($big->id))->not->toBeNull();
});

test('a restored or foreign retirement cannot be restored', function () {
    $item = Item::factory()->for($this->plan)->create();
    $retirement = ($this->retire)($item)->retirement;
    ($this->restore)($retirement);

    expect(mosErrors(fn () => ($this->restore)($retirement->fresh())))->toHaveKey('retirement');

    $foreign = Item::factory()->create();
    $foreignRetirement = app(RetireElement::class)(Actor::ownerWeb($foreign->objective->user), $foreign, 'ya no hace falta esto')->retirement;

    expect(fn () => ($this->restore)($foreignRetirement))->toThrow(ModelNotFoundException::class);
});

test('an AI actor cannot restore directly: it is a major operation', function () {
    $item = Item::factory()->for($this->plan)->create();
    $retirement = ($this->retire)($item)->retirement;

    expect(fn () => ($this->restore)($retirement, Actor::aiMcp($this->objective->user)))->toThrow(MajorOperationRequiresProposal::class)
        ->and(Item::query()->find($item->id))->toBeNull();
});

test('restoring an open prerequisite releases its active dependent, which is locked again', function () {
    $a = Item::factory()->for($this->plan)->create();
    $b = Item::factory()->for($this->plan)->create();
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);
    $retirement = ($this->retire)($a, 'ya no hace falta esto', RetirementDecision::ArchiveAsIs)->retirement;
    $b->update(['is_active' => true]);
    $session = FocusSession::query()->create(['item_id' => $b->id, 'started_at' => now()->subMinutes(5)]);

    ($this->restore)($retirement);

    expect($b->fresh()->is_active)->toBeFalse()
        ->and($b->fresh()->deriveState())->toBe(ItemState::Locked)
        ->and($session->fresh()->end_reason)->toBe('relocked');
});

test('restoring an element that is no longer retired is refused cleanly', function () {
    $item = Item::factory()->for($this->plan)->create();
    $retirement = ($this->retire)($item)->retirement;
    Item::withRetired()->whereKey($item->id)->update(['retired_at' => null]);

    expect(mosErrors(fn () => ($this->restore)($retirement))['retirement'][0])->toBe('Esto ya volvió al mapa.');
});
