<?php

use App\Actions\ControlMap\AddControlMapEntry;
use App\Actions\ControlMap\ConvertControlMapEntry;
use App\Actions\ControlMap\RemoveControlMapEntry;
use App\Actions\ControlMap\UpdateControlMapEntry;
use App\Actions\Support\Actor;
use App\Enums\ControlZone;
use App\Enums\ItemKind;
use App\Models\ControlMapEntry;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;

/*
| Task 2.8 — control map CRUD on objectives and plans; entries in "does not
| depend on me" can never become tasks (control-plan spec "The Control Map
| Separates Three Zones").
*/

test('entries are added per zone, appended in order, on objectives and on plans', function () {
    $objective = Objective::factory()->withControlPlan()->create();
    $plan = Plan::factory()->for($objective)->create();
    $actor = Actor::ownerWeb($objective->user);

    $first = app(AddControlMapEntry::class)($actor, $objective, ControlZone::Mine, 'Abrir la libreta a las 06:45');
    $second = app(AddControlMapEntry::class)($actor, $objective, ControlZone::Mine, 'Capturar 10 min antes de desayunar');
    app(AddControlMapEntry::class)($actor, $plan, ControlZone::Outside, 'Cortes de luz');

    expect($objective->controlMapEntries()->pluck('text')->all())->toBe(['Abrir la libreta a las 06:45', 'Capturar 10 min antes de desayunar'])
        ->and($second->position)->toBeGreaterThan($first->position)
        ->and($plan->controlMapEntries()->first()->zone)->toBe(ControlZone::Outside);
});

test('an entry can be edited and moved between zones, and removed', function () {
    $objective = Objective::factory()->withControlPlan()->create();
    $entry = ControlMapEntry::factory()->for($objective, 'plannable')->create(['zone' => ControlZone::Mine, 'text' => 'Antes']);
    $actor = Actor::ownerWeb($objective->user);

    app(UpdateControlMapEntry::class)($actor, $entry, ['text' => 'Después', 'zone' => 'influence']);

    expect($entry->fresh()->text)->toBe('Después')->and($entry->fresh()->zone)->toBe(ControlZone::Influence);

    app(RemoveControlMapEntry::class)($actor, $entry);

    expect(ControlMapEntry::query()->count())->toBe(0);
});

test('an entry the owner controls converts into a task of a plan, keeping the entry', function (ControlZone $zone) {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'WEB']);
    $plan = Plan::factory()->for($objective)->create();
    $entry = ControlMapEntry::factory()->for($objective, 'plannable')->create(['zone' => $zone, 'text' => 'Escribir el spec de Ahora']);

    $item = app(ConvertControlMapEntry::class)(Actor::ownerWeb($objective->user), $entry, $plan, [
        'two_minute_version' => 'abrir el documento del spec',
    ]);

    expect($item)->toBeInstanceOf(Item::class)
        ->and($item->title)->toBe('Escribir el spec de Ahora')
        ->and($item->kind)->toBe(ItemKind::Task)
        ->and($item->plan_id)->toBe($plan->id)
        ->and($item->key)->toBe('WEB-1')
        ->and($entry->fresh())->not->toBeNull();
})->with([ControlZone::Mine, ControlZone::Influence]);

test('an outside-control entry cannot become a task', function () {
    $objective = Objective::factory()->withControlPlan()->create();
    $plan = Plan::factory()->for($objective)->create();
    $entry = ControlMapEntry::factory()->for($objective, 'plannable')->create(['zone' => ControlZone::Outside]);

    expect(fn () => app(ConvertControlMapEntry::class)(Actor::ownerWeb($objective->user), $entry, $plan, [
        'two_minute_version' => 'algo',
    ]))->toThrow(ValidationException::class, 'no se convierte en tarea');

    expect(Item::query()->count())->toBe(0);
});

test('the web never offers the conversion for an outside entry and refuses it if forced', function () {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $mine = ControlMapEntry::factory()->for($objective, 'plannable')->create(['zone' => ControlZone::Mine]);
    $outside = ControlMapEntry::factory()->for($objective, 'plannable')->create(['zone' => ControlZone::Outside]);

    $this->actingAs($objective->user)
        ->get(route('objectives.show', 'DIARIO'))
        ->assertInertia(fn ($page) => $page
            ->where('controlMap.0.id', $mine->id)
            ->where('controlMap.0.can_become_task', true)
            ->where('controlMap.1.id', $outside->id)
            ->where('controlMap.1.can_become_task', false));

    $this->post(route('objectives.control-map.convert', ['objective' => 'DIARIO', 'entry' => $outside->id]), [
        'plan_id' => $plan->id,
        'two_minute_version' => 'algo',
    ])->assertSessionHasErrors('entry');

    expect(Item::query()->count())->toBe(0);
});

test('control map routes are scoped to the objective and to its owner', function () {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'DIARIO']);
    $other = Objective::factory()->withControlPlan()->create(['key' => 'OTRO']);
    $foreignEntry = ControlMapEntry::factory()->for($other, 'plannable')->create();

    $this->actingAs($objective->user)
        ->patch(route('objectives.control-map.update', ['objective' => 'DIARIO', 'entry' => $foreignEntry->id]), ['text' => 'x'])
        ->assertNotFound();

    $this->actingAs($objective->user)
        ->post(route('objectives.control-map.store', 'OTRO'), ['zone' => 'mine', 'text' => 'x'])
        ->assertNotFound();

    $this->actingAs($objective->user)
        ->post(route('objectives.control-map.store', 'DIARIO'), ['zone' => 'nowhere', 'text' => 'x'])
        ->assertSessionHasErrors('zone');
});
