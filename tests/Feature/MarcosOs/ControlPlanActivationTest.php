<?php

use App\Actions\Objectives\ActivateObjective;
use App\Actions\Objectives\CreateObjective;
use App\Actions\Objectives\UpdateObjective;
use App\Actions\Plans\ActivatePlan;
use App\Actions\Plans\CreatePlan;
use App\Actions\Support\Actor;
use App\Enums\ControlZone;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Models\ControlPlan;
use App\Models\Objective;
use App\Models\User;

/*
| Task 2.7 — 5-point plan validation and activation rules (control-plan spec
| "Every Objective And Plan Carries A 5-Point Plan"; projects spec
| "Objectives Are Created Only Through An Accepted Negotiation Or A Complete
| Control Plan"). One metric only; Spanish messages naming the missing point.
*/

test('a complete 5-point plan creates an active objective with its control plan and control map', function () {
    $owner = User::factory()->create();

    $objective = app(CreateObjective::class)(Actor::ownerWeb($owner), mosObjectiveData());

    expect($objective->state)->toBe(ObjectiveState::Active)
        ->and($objective->user_id)->toBe($owner->id)
        ->and($objective->key)->toBe('WEB')
        ->and($objective->controlPlan->isComplete())->toBeTrue()
        ->and($objective->controlPlan->metric_name)->toBe('Pantallas en uso diario')
        ->and((float) $objective->controlPlan->metric_target)->toBe(2.0)
        ->and($objective->controlPlan->deadline->toDateString())->toBe('2026-11-29')
        ->and($objective->controlMapEntries->pluck('zone')->all())->toBe([ControlZone::Mine, ControlZone::Influence, ControlZone::Outside]);
});

test('an objective without a metric cannot be activated and the message names the missing point', function () {
    $owner = User::factory()->create();

    $errors = mosErrors(fn () => app(CreateObjective::class)(Actor::ownerWeb($owner), mosObjectiveData(['metric' => null])));

    expect($errors)->toHaveKey('metric')
        ->and($errors['metric'][0])->toContain('Falta la métrica')
        ->and(array_keys($errors))->toBe(['metric'])
        ->and(Objective::query()->count())->toBe(0);
});

test('each missing point is named in Spanish', function (string $field, mixed $empty, string $message) {
    $errors = mosErrors(fn () => app(CreateObjective::class)(Actor::ownerWeb(User::factory()->create()), mosObjectiveData([$field => $empty])));

    expect($errors[$field === 'metric' ? 'metric' : $field][0])->toBe($message);
})->with([
    'outcome' => ['outcome', '', ControlPlan::MISSING_MESSAGES['outcome']],
    'deadline' => ['deadline', null, ControlPlan::MISSING_MESSAGES['deadline']],
    'metric without target' => ['metric', ['name' => 'Algo', 'target' => null], ControlPlan::MISSING_MESSAGES['metric']],
    'risks' => ['risks', [], ControlPlan::MISSING_MESSAGES['risks']],
    'blank risks' => ['risks', ['  '], ControlPlan::MISSING_MESSAGES['risks']],
    'contingency' => ['contingency', '', ControlPlan::MISSING_MESSAGES['contingency']],
]);

test('a draft objective with a complete plan becomes active; an incomplete one is refused', function () {
    $complete = Objective::factory()->draft()->withControlPlan()->create();
    $incomplete = Objective::factory()->draft()->create();
    ControlPlan::factory()->incomplete()->for($incomplete, 'plannable')->create();

    app(ActivateObjective::class)(Actor::ownerWeb($complete->user), $complete);

    $errors = mosErrors(fn () => app(ActivateObjective::class)(Actor::ownerWeb($incomplete->user), $incomplete));

    expect($complete->fresh()->state)->toBe(ObjectiveState::Active)
        ->and($incomplete->fresh()->state)->toBe(ObjectiveState::Draft)
        ->and($errors)->toHaveKeys(['deadline', 'metric', 'risks', 'contingency']);
});

test('an active objective cannot lose one of its five points on edit', function () {
    $objective = Objective::factory()->withControlPlan()->create();

    $errors = mosErrors(fn () => app(UpdateObjective::class)(Actor::ownerWeb($objective->user), $objective, ['contingency' => '']));

    expect($errors)->toHaveKey('contingency')
        ->and($objective->controlPlan->fresh()->contingency)->not->toBe('');
});

test('editing an active objective updates its title, identity and 5-point plan', function () {
    $objective = Objective::factory()->withControlPlan()->create();

    app(UpdateObjective::class)(Actor::ownerWeb($objective->user), $objective, [
        'title' => 'Nuevo título',
        'identity_statement' => null,
        'metric' => ['name' => 'Días con el ciclo', 'target' => 14, 'current' => 3],
    ]);

    $objective->refresh();

    expect($objective->title)->toBe('Nuevo título')
        ->and($objective->identity_statement)->toBeNull()
        ->and($objective->controlPlan->metric_name)->toBe('Días con el ciclo')
        ->and((float) $objective->controlPlan->metric_current)->toBe(3.0);
});

test('a plan is saved as a draft with only a title and activates only when its 5 points are complete', function () {
    $objective = Objective::factory()->withControlPlan()->create();
    $actor = Actor::ownerWeb($objective->user);

    $plan = app(CreatePlan::class)($actor, $objective, ['title' => 'Semana 2: afinar Ahora', 'level' => 2]);

    expect($plan->state)->toBe(PlanState::Draft)->and($plan->level)->toBe(2);

    $errors = mosErrors(fn () => app(ActivatePlan::class)($actor, $plan));
    expect($errors)->toHaveKeys(['outcome', 'deadline', 'metric', 'risks', 'contingency']);

    $plan->controlPlan()->updateOrCreate([], ControlPlan::factory()->raw());
    app(ActivatePlan::class)($actor, $plan->fresh());

    expect($plan->fresh()->state)->toBe(PlanState::Active);
});

test('creating a plan with activation requested and an incomplete plan is refused', function () {
    $objective = Objective::factory()->withControlPlan()->create();

    $errors = mosErrors(fn () => app(CreatePlan::class)(Actor::ownerWeb($objective->user), $objective, [
        'title' => 'Semana 1',
        'activate' => true,
        'outcome' => 'Cumplir el ciclo 5 de 7 días',
    ]));

    expect($errors)->toHaveKeys(['deadline', 'metric', 'risks', 'contingency'])
        ->and($objective->plans()->count())->toBe(0);
});

test('two metrics are rejected over http with the one-metric message', function (string $route) {
    $objective = Objective::factory()->withControlPlan()->create(['key' => 'DIARIO']);
    $this->actingAs($objective->user);

    $twoMetrics = [
        ['name' => 'Días', 'target' => 14],
        ['name' => 'Pantallas', 'target' => 2],
    ];

    $url = $route === 'objective' ? route('objectives.store') : route('objectives.plans.store', $objective->key);
    $payload = $route === 'objective'
        ? mosObjectiveData(['metric' => $twoMetrics])
        : ['title' => 'Semana 1', 'metric' => $twoMetrics];

    $this->post($url, $payload)->assertSessionHasErrors([
        'metric' => $route === 'objective'
            ? 'Solo una métrica por objetivo. Elegí la que mejor te diga si vas bien.'
            : 'Solo una métrica por plan. Elegí la que mejor te diga si vas bien.',
    ]);
})->with(['objective', 'plan']);

test('a second metric sent as a separate field is rejected too', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('objectives.store'), mosObjectiveData([
        'metrics' => [['name' => 'Otra', 'target' => 1]],
    ]))->assertSessionHasErrors(['metrics' => 'Solo una métrica por objetivo. Elegí la que mejor te diga si vas bien.']);

    expect(Objective::query()->count())->toBe(0);
});

test('a duplicate key is rejected in Spanish', function () {
    Objective::factory()->create(['key' => 'SALUD']);
    $this->actingAs(User::factory()->create());

    $this->post(route('objectives.store'), mosObjectiveData(['key' => 'SALUD']))
        ->assertSessionHasErrors(['key' => 'Ya existe un objetivo con esa clave.']);
});

test('the key is 2 to 10 uppercase letters', function (string $key) {
    $this->actingAs(User::factory()->create());

    $this->post(route('objectives.store'), mosObjectiveData(['key' => $key]))
        ->assertSessionHasErrors('key');
})->with(['A', 'ABCDEFGHIJK', 'salud', 'SAL1', 'SA-L']);
