<?php

use App\Actions\Items\AddItem;
use App\Actions\Items\CheckItem;
use App\Actions\Items\MoveItem;
use App\Actions\Items\UncheckItem;
use App\Actions\Items\UpdateItem;
use App\Enums\ItemKind;
use App\Enums\ItemState;
use App\Enums\TwoMinuteSource;
use App\Models\FocusSession;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
| Task 2.10 — item actions (issues spec): add with a required 2-minute
| version, edit (plan only within the same objective), check, uncheck, and a
| milestone that requires evidence.
*/

test('an item is appended at the end of its plan with the next number of its objective', function () {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    Item::factory()->for($plan)->count(2)->create();

    $item = app(AddItem::class)(mosOwner($plan), $plan, [
        'kind' => 'milestone',
        'title' => 'Semana 1: boceto de Ahora',
        'two_minute_version' => 'abrir una hoja y dibujar un rectángulo',
    ]);

    expect($item->key)->toBe('DIARIO-3')
        ->and($item->kind)->toBe(ItemKind::Milestone)
        ->and($plan->items()->pluck('id')->last())->toBe($item->id)
        ->and($item->state)->toBe(ItemState::Available);
});

test('an item without a 2-minute version is rejected with a Spanish message asking for it', function () {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();

    $this->actingAs($objective->user)
        ->post(route('objectives.plans.items.store', ['objective' => 'DIARIO', 'plan' => $plan->id]), [
            'kind' => 'task',
            'title' => 'Escribir qué me frenó hoy',
        ])
        ->assertSessionHasErrors(['two_minute_version' => 'Falta la versión de 2 minutos: es lo primero que vas a ver en Ahora.']);

    expect(Item::query()->count())->toBe(0);
});

test('an item can be added with prerequisites from any objective', function () {
    $objective = Objective::factory()->withControlPlan()->create();
    $plan = Plan::factory()->for($objective)->create();
    $otherObjective = Objective::factory()->for($objective->user)->withControlPlan()->create();
    $elsewhere = Item::factory()->for(Plan::factory()->for($otherObjective))->create();

    $item = app(AddItem::class)(mosOwner($plan), $plan, [
        'title' => 'Poster desde el teléfono',
        'two_minute_version' => 'abrir Poster en el teléfono',
        'prerequisite_ids' => [$elsewhere->id],
    ]);

    expect($item->prerequisites->pluck('id')->all())->toBe([$elsewhere->id])
        ->and($item->fresh()->state)->toBe(ItemState::Locked);
});

test('the owner edits title, description, 2-minute version, target date and plan within the objective', function () {
    $objective = Objective::factory()->withControlPlan()->create();
    [$planA, $planB] = Plan::factory()->for($objective)->count(2)->create()->all();
    Item::factory()->for($planB)->create();
    $item = Item::factory()->for($planA)->create(['two_minute_version' => 'abrir el editor']);

    Carbon::setTestNow('2026-09-27 15:00:00');

    app(UpdateItem::class)(mosOwner($item), $item, [
        'title' => 'Captura 10 min',
        'description' => 'Volcar todo al inbox',
        'two_minute_version' => 'escribir el título',
        'target_date' => '2026-09-30',
        'plan_id' => $planB->id,
    ]);

    $item->refresh();

    expect($item->title)->toBe('Captura 10 min')
        ->and($item->description)->toBe('Volcar todo al inbox')
        ->and($item->two_minute_version)->toBe('escribir el título')
        ->and($item->target_date->toDateString())->toBe('2026-09-30')
        ->and($item->plan_id)->toBe($planB->id)
        ->and($planB->items()->pluck('id')->last())->toBe($item->id)
        ->and($item->twoMinuteHistory)->toHaveCount(1)
        ->and($item->twoMinuteHistory->first()->text)->toBe('abrir el editor')
        ->and($item->twoMinuteHistory->first()->source)->toBe(TwoMinuteSource::Owner);
});

test('moving an item to a plan of another objective is rejected', function () {
    $item = Item::factory()->create();
    $foreignPlan = Plan::factory()->create();

    expect(fn () => app(UpdateItem::class)(mosOwner($item), $item, ['plan_id' => $foreignPlan->id]))
        ->toThrow(ValidationException::class, 'mismo objetivo');

    expect($item->fresh()->plan_id)->not->toBe($foreignPlan->id);
});

test('the 2-minute version cannot be cleared', function () {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'DIARIO']);
    $item = Item::factory()->for(Plan::factory()->for($objective))->create();

    $this->actingAs($objective->user)
        ->patch(route('objectives.items.update', ['objective' => 'DIARIO', 'item' => $item->key]), ['two_minute_version' => ''])
        ->assertSessionHasErrors('two_minute_version');
});

test('items are reordered within their plan', function () {
    $plan = Plan::factory()->create();
    [$a, $b, $c] = Item::factory()->for($plan)->count(3)->create()->all();

    app(MoveItem::class)(mosOwner($plan), $c, -1);

    expect($plan->items()->pluck('id')->all())->toBe([$a->id, $c->id, $b->id]);
});

test('a task is completed with a single check and a UTC timestamp', function () {
    Carbon::setTestNow('2026-09-27 21:10:00');
    $item = Item::factory()->create();

    $result = app(CheckItem::class)(mosOwner($item), $item);

    expect($result->item->state)->toBe(ItemState::Done)
        ->and($item->fresh()->completed_at->toIso8601String())->toBe('2026-09-27T21:10:00+00:00');
});

test('a locked item cannot be checked and stays locked', function () {
    $plan = Plan::factory()->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    expect(fn () => app(CheckItem::class)(mosOwner($b), $b))->toThrow(ValidationException::class, 'bloqueada');

    expect($b->fresh()->state)->toBe(ItemState::Locked);
});

test('completing the last prerequisite makes the dependent available and reports it', function () {
    $plan = Plan::factory()->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    $result = app(CheckItem::class)(mosOwner($a), $a);

    expect($b->fresh()->state)->toBe(ItemState::Available)
        ->and($result->unlocked->pluck('id')->all())->toBe([$b->id]);
});

test('checking an already done item is idempotent', function () {
    $item = Item::factory()->done()->create();
    $before = $item->completed_at->toIso8601String();

    $result = app(CheckItem::class)(mosOwner($item), $item);

    expect($result->unlocked)->toBeEmpty()
        ->and($item->fresh()->completed_at->toIso8601String())->toBe($before);
});

test('a milestone cannot be completed without evidence', function (?string $evidence) {
    $milestone = Item::factory()->milestone()->create();

    expect(fn () => app(CheckItem::class)(mosOwner($milestone), $milestone, $evidence))
        ->toThrow(ValidationException::class, 'evidencia');

    expect($milestone->fresh()->completed_at)->toBeNull();
})->with([null, '', '   ']);

test('a milestone completed with evidence keeps it', function () {
    $milestone = Item::factory()->milestone()->create();

    app(CheckItem::class)(mosOwner($milestone), $milestone, 'Boceto en la libreta, página 12', 'https://example.test/foto');

    expect($milestone->fresh()->completed_at)->not->toBeNull()
        ->and($milestone->evidence->text)->toBe('Boceto en la libreta, página 12')
        ->and($milestone->evidence->link)->toBe('https://example.test/foto');
});

test('checking the active item ends its focus session', function () {
    $item = Item::factory()->active()->create();
    FocusSession::query()->create(['item_id' => $item->id, 'started_at' => now()->subMinutes(13)]);

    app(CheckItem::class)(mosOwner($item), $item);

    expect($item->fresh()->is_active)->toBeFalse()
        ->and(FocusSession::query()->first()->ended_at)->not->toBeNull();
});

test('unchecking relocks a dependent, and an active dependent returns to locked keeping its focus history', function () {
    $plan = Plan::factory()->create();
    $a = Item::factory()->for($plan)->done()->create();
    $b = Item::factory()->for($plan)->active()->create();
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);
    FocusSession::query()->create(['item_id' => $b->id, 'started_at' => now()->subMinutes(5)]);

    app(UncheckItem::class)(mosOwner($a), $a);

    expect($a->fresh()->state)->toBe(ItemState::Available)
        ->and($b->fresh()->state)->toBe(ItemState::Locked)
        ->and($b->focusSessions()->count())->toBe(1)
        ->and($b->focusSessions()->first()->ended_at)->not->toBeNull();
});

test('a checked item can be unchecked from the web without penalty', function () {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'DIARIO']);
    $item = Item::factory()->for(Plan::factory()->for($objective))->done()->create();

    $this->actingAs($objective->user)
        ->post(route('objectives.items.uncheck', ['objective' => 'DIARIO', 'item' => $item->key]))
        ->assertRedirect();

    expect($item->fresh()->completed_at)->toBeNull();
});

test('the web check route checks a task and requires evidence for a milestone', function () {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $task = Item::factory()->for($plan)->create();
    $milestone = Item::factory()->for($plan)->milestone()->create();
    $this->actingAs($objective->user);

    $this->post(route('objectives.items.check', ['objective' => 'DIARIO', 'item' => $task->key]))->assertRedirect();
    $this->post(route('objectives.items.check', ['objective' => 'DIARIO', 'item' => $milestone->key]), ['evidence' => ''])
        ->assertSessionHasErrors('evidence');

    expect($task->fresh()->completed_at)->not->toBeNull()
        ->and($milestone->fresh()->completed_at)->toBeNull();
});

test('items of a closed objective are read-only', function () {
    $objective = Objective::factory()->closed()->withControlPlan()->create();
    $item = Item::factory()->for(Plan::factory()->for($objective))->create();

    expect(fn () => app(CheckItem::class)(mosOwner($item), $item))->toThrow(ValidationException::class, 'cerrado');
});
