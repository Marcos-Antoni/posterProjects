<?php

use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/*
| Tasks 2.13 / 2.14 in a real browser: the objective form only activates with
| the five points, a plan gets a task with its 2-minute version, the item is
| checked from its deep link, a cycle is refused naming its path, and a past
| target date is a neutral question.
*/

test('an objective is created only when its five points are complete, then shows its tree', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/objectives/create');

    $page->assertSee('Nuevo objetivo')
        ->assertSee('Para activar')
        ->assertButtonDisabled('Crear objetivo')
        ->fill('input[name="title"]', 'Marcos OS web v1')
        ->fill('input[name="key"]', 'web')
        ->assertValue('input[name="key"]', 'WEB')
        ->fill('textarea[name="outcome"]', 'La pantalla Ahora funciona y la uso todos los días.')
        ->fill('input[name="deadline"]', '2026-11-29')
        ->fill('input[name="risks[]"]', 'Me meto a rediseñar todo.')
        ->fill('input[name="contingency_when"]', 'me descubra rediseñando')
        ->fill('input[name="contingency_then"]', 'lo anoto y vuelvo a la tarea activa')
        ->assertSee('Una métrica: falta')
        ->assertButtonDisabled('Crear objetivo')
        ->fill('input[name="metric_name"]', 'Pantallas en uso diario')
        ->fill('input[name="metric_target"]', '2')
        ->assertButtonEnabled('Crear objetivo')
        ->click('Crear objetivo')
        ->assertPathIs('/objectives/WEB')
        ->assertSee('Marcos OS web v1')
        ->assertSee('Plan de 5 puntos')
        ->assertSee('Mapa de control')
        ->assertNoJavascriptErrors();

    expect(Objective::query()->where('key', 'WEB')->value('state')->value)->toBe('active');
});

test('a task is added with its 2-minute version and checked from its deep link, unlocking the next one', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->withControlPlan()->create(['title' => 'Semana 1']);
    $first = Item::factory()->for($plan)->create(['title' => 'Mesa lista']);
    $this->actingAs($owner);

    $page = visit("/objectives/DIARIO/plans/{$plan->id}");

    $page->assertSee('Agregar al final del plan')
        ->assertSee('Será DIARIO-2')
        ->fill('input[name="title"]', 'Captura 10 min')
        ->assertSee('Falta la versión de 2 minutos')
        ->assertButtonDisabled('Agregar tarea')
        ->fill('input[name="two_minute_version"]', 'abrir el inbox y escribir una línea')
        ->click('Agregar tarea')
        ->assertSee('DIARIO-2')
        ->assertNoJavascriptErrors();

    $second = Item::query()->where('title', 'Captura 10 min')->firstOrFail();
    ItemDependency::query()->create(['prerequisite_id' => $first->id, 'dependent_id' => $second->id]);

    $item = visit('/objectives/DIARIO/items/DIARIO-2');
    $item->assertSee('Se abre al terminar')
        ->assertDontSee('Marcar hecho')
        ->assertNoJavascriptErrors();

    $item = visit('/objectives/DIARIO/items/DIARIO-1');
    $item->click('.ihead .btn-primary')
        ->assertSee('Se abrió: Captura 10 min')
        ->assertSee('Desmarcar')
        ->assertNoJavascriptErrors();

    expect($first->fresh()->completed_at)->not->toBeNull();
});

test('a dependency that would close a cycle is refused naming the path', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    $a = Item::factory()->for($plan)->create(['title' => 'Captura 10 min']);
    $b = Item::factory()->for($plan)->create(['title' => 'Clasificar y elegir prioridad']);
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);
    $this->actingAs($owner);

    visit('/objectives/DIARIO/items/DIARIO-1')
        ->click('Agregar una dependencia')
        ->fill('input[placeholder="Ej.: DIARIO-3"]', 'DIARIO-2')
        ->click('Agregar')
        ->assertSee('No se puede: armaría un círculo.')
        ->assertSee('DIARIO-2 Clasificar y elegir prioridad → DIARIO-1 Captura 10 min')
        ->assertNoJavascriptErrors();
});

test('a past target date is a neutral question, never the danger color', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    Item::factory()->for(Plan::factory()->for($objective))->create(['target_date' => now()->subDays(2)->toDateString()]);
    $this->actingAs($owner);

    visit('/objectives/DIARIO/items/DIARIO-1')
        ->assertSee('La fecha objetivo pasó. ¿La ajustamos?')
        ->assertSee('Disponible')
        ->assertScript(
            "(() => { const el = [...document.querySelectorAll('.neutral .muted')][0]; const danger = getComputedStyle(document.documentElement).getPropertyValue('--destructive').trim(); return getComputedStyle(el).color !== danger; })()",
            true,
        )
        ->assertNoJavascriptErrors();
});

test('the objective screens render in dark mode without errors', function () {
    $owner = User::factory()->create(['appearance' => 'dark']);
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    Item::factory()->for(Plan::factory()->for($objective))->create();
    $this->actingAs($owner);

    visit(['/objectives', '/objectives/DIARIO', '/objectives/DIARIO/items/DIARIO-1'])
        ->assertNoJavascriptErrors();
});
