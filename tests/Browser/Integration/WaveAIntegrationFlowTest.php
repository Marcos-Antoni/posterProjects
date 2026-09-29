<?php

use App\Actions\Items\StartItem;
use App\Actions\Support\Actor;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/*
| Wave A integration in a real browser: the active station of the objective
| graph (screen 8) offers "Seguir en Ahora" as its primary action (P5 NEED to
| P3, mockup 08), which opens the Now screen on that same task.
*/

test('the active station of the objective map continues on Ahora', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario']);
    $plan = Plan::factory()->for($objective)->withControlPlan()->create(['title' => 'Semana 1']);
    $active = Item::factory()->for($plan)->create(['title' => 'Captura 10 min', 'two_minute_version' => 'abrir el inbox y escribir una línea']);
    Item::factory()->for($plan)->create(['title' => 'Clasificar y elegir prioridad']);
    app(StartItem::class)(Actor::ownerWeb($owner), $active);
    $this->actingAs($owner);

    $page = visit('/map/DIARIO');

    $page->click('[data-id="DIARIO-1"] .hit')
        ->assertSeeIn('.gside', 'Seguir en Ahora')
        ->click('Seguir en Ahora')
        ->assertPathIs('/now')
        ->assertSee('Captura 10 min')
        ->assertNoJavascriptErrors();
});
