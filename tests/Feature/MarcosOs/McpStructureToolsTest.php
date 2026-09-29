<?php

use App\Actions\Retirement\RetireElement;
use App\Actions\Support\Actor;
use App\Actions\Support\MajorOperationRequiresProposal;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Items\AddDependency;
use App\Mcp\Tools\Items\AddItems;
use App\Mcp\Tools\Items\RemoveDependency;
use App\Mcp\Tools\Items\UpdateItem;
use App\Mcp\Tools\Objectives\CreateObjective;
use App\Mcp\Tools\Objectives\UpdateObjective;
use App\Mcp\Tools\Plans\CreatePlan;
use App\Mcp\Tools\Plans\UpdatePlan;
use App\Models\AiAuditLog;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/*
| 2026-09-29 decision (supersedes the old R17 minor/major split for
| structural operations, LOG.md): the AI creates and edits structure
| directly through its own MCP tools — create-objective, update-objective,
| create-plan, update-plan, add-items, update-item, add-dependency,
| remove-dependency — each reusing the existing domain action and the same
| validation the web form requests enforce, applied immediately and
| audited. Only retiring or restoring an element still goes through
| `propose`.
*/

test('create-objective creates the full tree — objective, control plan, plan, items and a dependency — in one call, and every step is audited', function () {
    $user = User::factory()->create();

    $response = PosterServer::actingAs($user)->tool(CreateObjective::class, [
        ...mosObjectiveData(['key' => 'AIOBJ']),
        'plans' => [
            [
                'title' => 'Semana 1',
                'items' => [
                    ['title' => 'Investigar', 'two_minute_version' => 'Abrir el navegador'],
                    ['title' => 'Escribir informe', 'kind' => 'milestone', 'two_minute_version' => 'Abrir el editor'],
                ],
            ],
        ],
        'dependencies' => [
            ['prerequisite' => 0, 'dependent' => 1],
        ],
    ]);

    $response->assertOk();

    $objective = Objective::query()->where('key', 'AIOBJ')->sole();
    $plan = $objective->plans()->sole();
    $items = $plan->items()->orderBy('id')->get();

    expect($objective->controlPlan)->not->toBeNull()
        ->and($objective->controlPlan->outcome)->not->toBeNull()
        ->and($plan->title)->toBe('Semana 1')
        ->and($items)->toHaveCount(2)
        ->and($items[1]->prerequisites->pluck('id')->all())->toBe([$items[0]->id]);

    $response->assertSee(route('objectives.show', 'AIOBJ'));

    $objectiveAudit = AiAuditLog::query()
        ->where('user_id', $user->id)
        ->where('operation', 'create-objective')
        ->sole();

    expect($objectiveAudit->tier)->toBe('minor')
        ->and($objectiveAudit->source)->toBe('ai-mcp')
        ->and($objectiveAudit->target_type)->toBe('objective')
        ->and($objectiveAudit->target_id)->toBe($objective->id);

    expect(AiAuditLog::query()->where('operation', 'create-plan')->count())->toBe(1)
        ->and(AiAuditLog::query()->where('operation', 'add-item')->count())->toBe(2)
        ->and(AiAuditLog::query()->where('operation', 'add-dependency')->count())->toBe(1);
});

test('create-objective with an incomplete control plan is refused, nothing written', function () {
    $user = User::factory()->create();

    PosterServer::actingAs($user)->tool(CreateObjective::class, [
        'key' => 'AIBAD',
        'title' => 'Objetivo incompleto',
        // outcome/deadline/metric/risks/contingency are missing on purpose.
    ])->assertHasErrors(['Falta el resultado']);

    expect(Objective::query()->where('key', 'AIBAD')->exists())->toBeFalse();
});

test('create-objective refuses a dependency index out of range, rolling back the whole tree', function () {
    $user = User::factory()->create();

    PosterServer::actingAs($user)->tool(CreateObjective::class, [
        ...mosObjectiveData(['key' => 'AIBADX']),
        'plans' => [
            ['title' => 'Semana 1', 'items' => [['title' => 'Solo uno', 'two_minute_version' => 'Empezar']]],
        ],
        'dependencies' => [['prerequisite' => 0, 'dependent' => 5]],
    ])->assertHasErrors(['no está en esta lista']);

    expect(Objective::query()->where('key', 'AIBADX')->exists())->toBeFalse();
});

test('update-item changes title, 2-minute version and target date directly, and it is audited', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create();
    $item = Item::factory()->for($plan)->create(['title' => 'Vieja', 'two_minute_version' => 'Abrir la app']);

    PosterServer::actingAs($user)->tool(UpdateItem::class, [
        'item_key' => $item->key,
        'title' => 'Nueva',
        'two_minute_version' => 'Abrir la app y anotar',
        'target_date' => '2026-12-01',
    ])->assertOk();

    $item = $item->fresh();

    expect($item->title)->toBe('Nueva')
        ->and($item->two_minute_version)->toBe('Abrir la app y anotar')
        ->and($item->target_date->toDateString())->toBe('2026-12-01')
        ->and($item->twoMinuteHistory()->sole()->text)->toBe('Abrir la app');

    $audit = AiAuditLog::query()->where('operation', 'update-item')->sole();

    expect($audit->tier)->toBe('minor')
        ->and($audit->source)->toBe('ai-mcp')
        ->and($audit->target_id)->toBe($item->id);
});

test('update-item never clears the 2-minute version', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->withControlPlan()->create(['key' => 'SALUD']);
    $item = Item::factory()->for(Plan::factory()->for($objective))->create();

    PosterServer::actingAs($user)->tool(UpdateItem::class, [
        'item_key' => $item->key,
        'two_minute_version' => '',
    ])->assertHasErrors(['se puede borrar']);
});

test('update-objective edits the title and control plan directly', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->withControlPlan()->create(['key' => 'SALUD', 'title' => 'Vieja']);

    PosterServer::actingAs($user)->tool(UpdateObjective::class, [
        'objective_key' => 'SALUD',
        'title' => 'Nueva',
    ])->assertOk();

    expect($objective->fresh()->title)->toBe('Nueva');

    $audit = AiAuditLog::query()->where('operation', 'update-objective')->sole();
    expect($audit->tier)->toBe('minor')->and($audit->target_id)->toBe($objective->id);
});

test('create-plan appends a draft plan to an existing objective directly', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->withControlPlan()->create(['key' => 'SALUD']);

    $response = PosterServer::actingAs($user)->tool(CreatePlan::class, [
        'objective_key' => 'SALUD',
        'title' => 'Semana 1',
    ]);

    $response->assertOk();

    $plan = $objective->plans()->sole();

    expect($plan->title)->toBe('Semana 1')
        ->and($plan->state->value)->toBe('draft');

    $audit = AiAuditLog::query()->where('operation', 'create-plan')->sole();
    expect($audit->tier)->toBe('minor')->and($audit->target_id)->toBe($plan->id);
});

test('update-plan edits a plan\'s title directly', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->draft()->create(['title' => 'Vieja']);

    PosterServer::actingAs($user)->tool(UpdatePlan::class, [
        'objective_key' => 'SALUD',
        'plan_id' => $plan->id,
        'title' => 'Nueva',
    ])->assertOk();

    expect($plan->fresh()->title)->toBe('Nueva');
});

test('add-items adds two items with a dependency between them to an existing plan, in one call', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create();

    $response = PosterServer::actingAs($user)->tool(AddItems::class, [
        'objective_key' => 'SALUD',
        'plan_id' => $plan->id,
        'items' => [
            ['title' => 'Primero', 'two_minute_version' => 'Empezar'],
            ['title' => 'Segundo', 'two_minute_version' => 'Seguir'],
        ],
        'dependencies' => [['prerequisite' => 0, 'dependent' => 1]],
    ]);

    $response->assertOk();

    $items = $plan->items()->orderBy('id')->get();

    expect($items)->toHaveCount(2)
        ->and($items[1]->prerequisites->pluck('id')->all())->toBe([$items[0]->id]);
});

test('add-dependency links two existing items, possibly across objectives, directly', function () {
    $user = User::factory()->create();
    $salud = Objective::factory()->for($user)->withControlPlan()->create(['key' => 'SALUD']);
    $dinero = Objective::factory()->for($user)->withControlPlan()->create(['key' => 'DINERO']);
    $a = Item::factory()->for(Plan::factory()->for($dinero))->create();
    $b = Item::factory()->for(Plan::factory()->for($salud))->create();

    PosterServer::actingAs($user)->tool(AddDependency::class, [
        'prerequisite_key' => $a->key,
        'dependent_key' => $b->key,
    ])->assertOk();

    expect(ItemDependency::query()->where('prerequisite_id', $a->id)->where('dependent_id', $b->id)->exists())->toBeTrue();
});

test('remove-dependency removes the edge directly', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->create();
    $b = Item::factory()->for($plan)->create();
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    PosterServer::actingAs($user)->tool(RemoveDependency::class, [
        'prerequisite_key' => $a->key,
        'dependent_key' => $b->key,
    ])->assertOk();

    expect(ItemDependency::query()->where('prerequisite_id', $a->id)->where('dependent_id', $b->id)->exists())->toBeFalse();
});

test('retiring still requires a proposal Marco accepts: an AI actor cannot retire directly', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->withControlPlan()->create(['key' => 'SALUD']);
    $item = Item::factory()->for(Plan::factory()->for($objective))->create();

    expect(fn () => app(RetireElement::class)(Actor::aiMcp($user), $item, 'Ya no hace falta, se resolvió de otra forma.'))
        ->toThrow(MajorOperationRequiresProposal::class);

    expect($item->fresh()->retired_at)->toBeNull();
});

test('cross-user isolation: another owner\'s objective, plan and item are not found through the new tools', function () {
    $owner = User::factory()->create();
    $foreign = Objective::factory()->withControlPlan()->create(['key' => 'AJENO']);
    $foreignPlan = Plan::factory()->for($foreign)->create();
    $foreignItem = Item::factory()->for($foreignPlan)->create();

    PosterServer::actingAs($owner)->tool(CreatePlan::class, [
        'objective_key' => 'AJENO',
        'title' => 'Intruso',
    ])->assertHasErrors(['Objective not found']);

    PosterServer::actingAs($owner)->tool(AddItems::class, [
        'objective_key' => 'AJENO',
        'plan_id' => $foreignPlan->id,
        'items' => [['title' => 'Intruso', 'two_minute_version' => 'Empezar']],
    ])->assertHasErrors(['Objective not found']);

    PosterServer::actingAs($owner)->tool(UpdateItem::class, [
        'item_key' => $foreignItem->key,
        'title' => 'Intruso',
    ])->assertHasErrors(['Item not found']);

    PosterServer::actingAs($owner)->tool(UpdateObjective::class, [
        'objective_key' => 'AJENO',
        'title' => 'Intruso',
    ])->assertHasErrors(['Objective not found']);

    expect($foreign->fresh()->title)->not->toBe('Intruso')
        ->and($foreignPlan->items()->count())->toBe(1)
        ->and($foreignItem->fresh()->title)->not->toBe('Intruso');
});
