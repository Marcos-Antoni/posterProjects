<?php

use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Tasks 5.2 / 5.3 in a real browser, with the mockups' 14-day program: the
| per-objective track (labels only on Now and the goal, the panel on
| selection, keyboard access, dependencies added with the cycle message, a
| check that plays the unlock) and the global map (one track per objective,
| the cross-objective connector, collapse, filters).
*/

/**
 * The mockups' data: DIARIO (Now = Captura 10 min, days 4–6 in parallel,
 * two milestones, one retired task), FINALES and WEB, where the milestone
 * "Especificar Ahora" of DIARIO unlocks WEB's first station.
 */
function seedMockupProgram(User $owner): void
{
    $link = fn (Item $a, Item $b) => ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    $diario = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario', 'position' => 0]);
    $diario->controlPlan->update(['metric_name' => 'Días con el ciclo diario completo', 'metric_target' => 14, 'metric_current' => 1, 'deadline' => '2026-10-11']);
    $week1 = Plan::factory()->for($diario)->create(['title' => 'Semana 1: primer ciclo']);
    $week2 = Plan::factory()->for($diario)->create(['title' => 'Semana 2: afinar Ahora']);
    $close = Plan::factory()->for($diario)->create(['title' => 'Cierre']);

    $d1 = Item::factory()->for($week1)->done()->create(['title' => 'Mesa lista']);
    $d2 = Item::factory()->for($week1)->active()->create(['title' => 'Captura 10 min', 'two_minute_version' => 'abrir el inbox y escribir una línea']);
    $d3 = Item::factory()->for($week1)->create(['title' => 'Clasificar y elegir prioridad']);
    $d4 = Item::factory()->for($week1)->create(['title' => 'Revisar video Física']);
    $d5 = Item::factory()->for($week1)->create(['title' => 'Revisar fórmula cuadrática']);
    $d6 = Item::factory()->for($week1)->create(['title' => 'Poster desde el teléfono']);
    $d7 = Item::factory()->for($week1)->milestone()->create(['title' => 'Semana 1: boceto de “Ahora”']);
    $d8 = Item::factory()->for($week2)->create(['title' => 'Elegir acción del lunes']);
    $d9 = Item::factory()->for($week2)->create(['title' => 'Hora prevista vs real']);
    $d13 = Item::factory()->for($week2)->milestone()->create(['title' => 'Especificar “Ahora”']);
    $d14 = Item::factory()->for($close)->create(['title' => 'Backlog y primera tarea']);
    $retired = Item::factory()->for($week1)->retired()->create(['title' => 'Diseñar segundo cerebro completo']);
    DB::table('retirements')->insert([
        'retirable_type' => 'item', 'retirable_id' => $retired->id, 'reason' => 'planificar sin práctica',
        'decision' => 'archive', 'retired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $link($d1, $d2);
    $link($d2, $d3);
    foreach ([$d4, $d5, $d6] as $parallel) {
        $link($d3, $parallel);
        $link($parallel, $d7);
    }
    $link($d7, $d8);
    $link($d8, $d9);
    $link($d9, $d13);
    $link($d13, $d14);

    $finales = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'FINALES', 'title' => 'Aprobar finales', 'position' => 1]);
    $fisica = Plan::factory()->for($finales)->create(['title' => 'Física II']);
    $b1 = Item::factory()->for($fisica)->done()->create(['title' => 'Física II: guía resuelta']);
    $b2 = Item::factory()->for($fisica)->create(['title' => 'Física II: examen']);
    $b3 = Item::factory()->for($fisica)->create(['title' => 'Micro: resumen']);
    $b4 = Item::factory()->for($fisica)->create(['title' => 'Micro: examen']);
    $link($b1, $b2);
    $link($b1, $b3);
    $link($b3, $b4);

    $web = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'WEB', 'title' => 'Marcos OS web v1', 'position' => 2]);
    $pantalla = Plan::factory()->for($web)->create(['title' => 'Pantalla Ahora']);
    $c1 = Item::factory()->for($pantalla)->create(['title' => 'Pantalla Ahora']);
    $c2 = Item::factory()->for($pantalla)->create(['title' => 'Captura rápida']);
    $link($c1, $c2);
    $link($d13, $c1);
}

function phase5Shot(mixed $page, string $name): void
{
    $page->screenshot(fullPage: true, filename: 'phase5-'.$name);
}

test('the objective track labels only Now and the goal, and opens the panel on click and on Enter', function () {
    $owner = User::factory()->create();
    seedMockupProgram($owner);
    $this->actingAs($owner);

    $page = visit('/map/DIARIO')->resize(1440, 1000);

    $page->assertSee('Marcos OS en uso diario')
        ->assertSee('1 de 14')
        ->assertSee('Faltan 5 estaciones')
        ->assertSee('Captura 10 min')
        ->assertSee('Revisión 11-oct')
        ->assertAriaAttribute('[data-id="DIARIO-3"]', 'label', 'Clasificar y elegir prioridad, bloqueada')
        ->assertAriaAttribute('[data-id="DIARIO-7"]', 'label', 'Hito Semana 1: boceto de “Ahora”, bloqueada')
        ->assertMissing('.gside .kicker')
        ->assertNoJavascriptErrors();

    // The name of a station without a label is only in its tooltip.
    expect($page->script('getComputedStyle(document.querySelector(\'[data-id="DIARIO-3"] .tip\')).opacity'))->toBe('0');

    phase5Shot($page, '08-light');

    $page->click('[data-id="DIARIO-3"] .hit')
        ->assertSeeIn('.gside', 'Clasificar y elegir prioridad')
        ->assertSeeIn('.gside', 'Se abre al terminar')
        ->assertSeeIn('.gside', 'Captura 10 min, activa ahora')
        ->assertSeeIn('.gside', 'Esto abre')
        ->assertSeeIn('.gside', 'Revisar fórmula cuadrática');

    phase5Shot($page, '08-panel');

    $page->keys('[data-id="DIARIO-3"]', 'Escape')
        ->assertMissing('.gside .kicker');

    $page->keys('[data-id="DIARIO-2"]', 'Enter')
        ->assertSeeIn('.gside', 'Activa, ahora')
        ->assertSeeIn('.gside', '2 min: abrir el inbox y escribir una línea')
        ->assertNoJavascriptErrors();
});

test('the retired toggle and the plan filter dim or hide without moving the track', function () {
    $owner = User::factory()->create();
    seedMockupProgram($owner);
    $this->actingAs($owner);

    $page = visit('/map/DIARIO');

    $page->assertPresent('.nd.ret')
        ->click('[data-id="retirado:DIARIO-12"] .hit')
        ->assertSeeIn('.gside', 'Razón: planificar sin práctica.')
        ->click('Mostrar retirados')
        ->assertScript('getComputedStyle(document.querySelector(".nd.ret")).display', 'none')
        ->click('.gtools .seg button:nth-child(3)')
        ->assertPresent('[data-id="DIARIO-1"].dim')
        ->assertNotPresent('[data-id="DIARIO-8"].dim')
        ->assertNoJavascriptErrors();
});

test('a cycle added from the graph panel is refused naming the path, a valid edge redraws the track', function () {
    $owner = User::factory()->create();
    seedMockupProgram($owner);
    $this->actingAs($owner);

    $page = visit('/map/DIARIO');

    $page->click('[data-id="DIARIO-1"] .hit')
        ->click('Agregar una dependencia')
        ->select('.depform select', 'prerequisite')
        ->fill('.depform input', 'diario-3')
        ->click('.depform button[type="submit"]')
        ->assertSeeIn('.gside .attn', 'No se puede: armaría un círculo.')
        ->assertSeeIn('.gside .attn', 'DIARIO-1 Mesa lista')
        ->assertNoJavascriptErrors();

    $page->fill('.depform input', 'FINALES-1')
        ->click('.depform button[type="submit"]')
        ->assertSeeIn('.gside', 'Física II: guía resuelta')
        ->assertPresent('[data-id="FINALES-1"] .n-stub')
        ->assertNoJavascriptErrors();

    expect(ItemDependency::query()->count())->toBe(18);
});

test('checking the Now task from the graph plays the unlock on it and on what it opened', function () {
    $owner = User::factory()->create();
    seedMockupProgram($owner);
    $this->actingAs($owner);

    $page = visit('/map/DIARIO');

    $page->click('[data-id="DIARIO-2"] .hit')
        ->click('Marcar hecha')
        ->assertAriaAttribute('[data-id="DIARIO-3"]', 'label', 'Clasificar y elegir prioridad, disponible')
        ->assertPresent('[data-id="DIARIO-2"].unlk .hl')
        ->assertPresent('[data-id="DIARIO-3"].unlk .hl')
        ->assertPresent('path.e.trace')
        ->assertSeeIn('.gside', 'Desmarcar (sin penalidad)')
        ->assertNoJavascriptErrors();

    // The ring animates only when motion is allowed; the ring itself (the
    // static highlight) is drawn either way.
    expect($page->script('getComputedStyle(document.querySelector(\'[data-id="DIARIO-3"] .hl\')).animationName'))->toBe('mos-unlock-ring')
        ->and($page->script(<<<'JS'
            (() => {
                const all = (rules) => [...rules].flatMap((rule) => [rule, ...(rule.cssRules ? all(rule.cssRules) : [])]);

                return all([...document.styleSheets].flatMap((sheet) => [...sheet.cssRules]))
                    .filter((rule) => rule instanceof CSSMediaRule && rule.conditionText.includes('prefers-reduced-motion') && rule.conditionText.includes('no-preference'))
                    .some((rule) => rule.cssText.includes('mos-unlock-ring') && rule.cssText.includes('mos-unlock-trace'));
            })()
            JS))->toBeTrue();

    phase5Shot($page, '08-unlocked');
});

test('the global map draws one track per active objective, the cross connector, and collapses a line', function () {
    $owner = User::factory()->create();
    seedMockupProgram($owner);
    $this->actingAs($owner);

    $page = visit('/map')->resize(1440, 1000);

    $page->assertSee('Mapa global')
        ->assertSee('Tres objetivos activos. Ahora estás en Captura 10 min.')
        ->assertSee('Aprobar finales')
        ->assertSee('Marcos OS web v1')
        ->assertPresent('[data-obj="WEB"] path.e-cross')
        ->assertPresent('[data-id="DIARIO-3"] .opens-halo')
        ->assertAttribute('nav[aria-label="Principal"] a[aria-current="page"]', 'href', '/map')
        ->assertNoJavascriptErrors();

    phase5Shot($page, '09-light');

    $page->click('[data-id="objetivo:FINALES"] .hit')
        ->assertSeeIn('.gside', '1 de 4 hechas, 2 disponibles.')
        ->click('Contraer esta línea')
        ->assertNotPresent('[data-id="FINALES-2"]')
        ->assertSee('1 de 4 hechas, 2 disponibles')
        ->click('Expandir esta línea')
        ->assertPresent('[data-id="FINALES-2"]')
        ->click('.gtools .seg button:last-child')
        ->assertPresent('[data-obj="DIARIO"].dim')
        ->assertNotPresent('[data-obj="WEB"].dim')
        ->assertNoJavascriptErrors();

    $page->click('[data-id="WEB-1"] .hit')
        ->assertSeeIn('.gside', 'Se abre al terminar')
        ->assertSeeIn('.gside', 'Especificar “Ahora” · DIARIO')
        ->click('Ver en su línea')
        ->assertPathIs('/map/WEB')
        ->assertPresent('[data-id="DIARIO-10"] .n-stub')
        ->assertSeeIn('.gside h2', 'Pantalla Ahora')
        ->assertNoJavascriptErrors();
});

test('both graphs render in dark mode and the objective screen links to its map', function () {
    $owner = User::factory()->create(['appearance' => 'dark']);
    seedMockupProgram($owner);
    $this->actingAs($owner);

    $page = visit('/map')->resize(1440, 1000);
    $page->assertNoJavascriptErrors();
    phase5Shot($page, '09-dark');

    $page = visit('/map/DIARIO')->resize(1440, 1000);
    $page->assertNoJavascriptErrors();
    phase5Shot($page, '08-dark');

    visit('/objectives/DIARIO')
        ->click('Ver su mapa')
        ->assertPathIs('/map/DIARIO')
        ->assertNoJavascriptErrors();
});
