<?php

use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Retirement;
use App\Models\User;

/*
| Tasks 6.5 in a real browser: the retire dialog (screen 23) keeps "Retirar"
| disabled until the reason has 10 characters, splits a task into smaller
| ones that inherit its place, and the Retired view (screen 22) shows it with
| its reason and brings it back to the map.
*/

test('a task is split from the retire dialog and brought back from Retirados', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario']);
    $plan = Plan::factory()->for($objective)->create(['title' => 'Semana 1']);
    $before = Item::factory()->for($plan)->done()->create(['title' => 'Captura 10 min']);
    $big = Item::factory()->for($plan)->create(['title' => 'Clasificar y elegir prioridad']);
    $after = Item::factory()->for($plan)->create(['title' => 'Revisar video Física']);
    ItemDependency::query()->create(['prerequisite_id' => $before->id, 'dependent_id' => $big->id]);
    ItemDependency::query()->create(['prerequisite_id' => $big->id, 'dependent_id' => $after->id]);
    $this->actingAs($owner);

    $page = visit('/objectives/DIARIO/items/DIARIO-2');

    $page->click('Retirar')
        ->assertSee('Retirar “Clasificar y elegir prioridad”')
        ->assertSee('Tarea 2 de Marcos OS en uso diario, plan Semana 1. Está disponible.')
        ->fill('reason', 'no va')
        ->assertSee('Escribí al menos 10 caracteres para que te sirva después. Faltan 5.')
        ->fill('reason', 'demasiado grande: mezcla dos decisiones')
        ->assertSee('Lista')
        ->click('Dividir')
        ->assertSee('Las dos heredan su lugar en el camino, en el mismo plan:')
        ->fill('internal:label="Nueva tarea a"', 'Clasificar las capturas del inbox')
        ->fill('internal:label="Versión de 2 minutos de la tarea a"', 'Mover una captura')
        ->fill('internal:label="Nueva tarea b"', 'Elegir la prioridad de la semana')
        ->fill('internal:label="Versión de 2 minutos de la tarea b"', 'Escribir un nombre')
        ->click('Retirar y dividir')
        ->assertPathIs("/objectives/DIARIO/plans/{$plan->id}")
        ->assertSee('Clasificar las capturas del inbox')
        ->assertSee('Elegir la prioridad de la semana')
        ->assertNoJavascriptErrors();

    expect(Item::query()->find($big->id))->toBeNull();

    $page = visit('/retired');

    $page->assertSee('Retirados')
        ->assertSee('Clasificar y elegir prioridad')
        ->assertSee('demasiado grande: mezcla dos decisiones')
        ->assertSee('Dividida en “Clasificar las capturas del inbox” y “Elegir la prioridad de la semana”')
        ->click('Devolver al mapa')
        ->assertSee('Vuelve a Marcos OS en uso diario como estaba antes: disponible.')
        ->click('internal:role=dialog >> internal:role=button[name="Devolver al mapa"]')
        ->assertSee('Todavía no retiraste nada')
        ->assertNoJavascriptErrors();

    expect(Item::query()->find($big->id))->not->toBeNull()
        ->and(Retirement::query()->sole()->restored_at)->not->toBeNull();
});

test('a plan is retired moving its tasks, and a task retired with its plan says why it cannot come back alone', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'FINALES', 'title' => 'Aprobar finales']);
    $plan = Plan::factory()->for($objective)->create(['title' => 'Repaso general']);
    $other = Plan::factory()->for($objective)->create(['title' => 'Física II']);
    Item::factory()->for($plan)->create(['title' => 'Hacer un simulacro cronometrado']);
    $third = Plan::factory()->for($objective)->create(['title' => 'Diseñar segundo cerebro']);
    Item::factory()->for($third)->create(['title' => 'Configurar Notion']);
    $this->actingAs($owner);

    visit("/objectives/FINALES/plans/{$plan->id}")
        ->click('Retirar el plan')
        ->assertSee('Retirar el plan “Repaso general”')
        ->fill('reason', 'lo reparto entre las materias')
        ->click('Mover')
        ->assertSee('Mover sus tareas a')
        ->click('Retirar y mover')
        ->assertPathIs('/objectives/FINALES')
        ->assertDontSee('Repaso general')
        ->assertNoJavascriptErrors();

    expect(Item::query()->where('plan_id', $other->id)->pluck('title')->all())->toBe(['Hacer un simulacro cronometrado']);

    visit("/objectives/FINALES/plans/{$third->id}")
        ->click('Retirar el plan')
        ->fill('reason', 'planificar sin práctica')
        ->click('Archivar tal cual')
        ->click('Retirar y archivar')
        ->assertPathIs('/objectives/FINALES');

    visit('/retired')
        ->assertSee('Lo que se repite, en 2 retiros')
        ->assertSee('Retirada junto con su plan')
        ->assertSee('Primero devolvé su plan, “Diseñar segundo cerebro”.')
        ->assertSee('Movido: su tarea pasó a “Física II”')
        ->click('button:has-text("Tareas")')
        ->assertDontSee('Movido: su tarea pasó')
        ->assertNoJavascriptErrors();
});
