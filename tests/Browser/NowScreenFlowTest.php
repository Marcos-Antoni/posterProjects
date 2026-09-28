<?php

use App\Enums\TwoMinuteSource;
use App\Models\FocusSession;
use App\Models\Habit;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/*
| Phase 3 in a real browser (screen 2, mockup 02-now.html): start with the
| 2-minute chip, the silent 25-minute cue from the persisted focus start
| (reload continuity, no focus steal, no live region, keyboard reachable),
| "Terminé" = "Marcar hecho" with the unlock moment (and its reduced-motion
| fallback), and "Estoy trabado" without AI.
*/

/**
 * @return array{0: User, 1: Item, 2: Item}
 */
function p3BrowserSetup(): array
{
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario']);
    $plan = Plan::factory()->for($objective)->create(['title' => 'Semana 1']);
    $task = Item::factory()->for($plan)->create([
        'title' => 'Captura 10 min',
        'two_minute_version' => 'abrir el inbox y escribir una línea',
    ]);
    $next = Item::factory()->for($plan)->create(['title' => 'Clasificar y elegir prioridad']);
    ItemDependency::query()->create(['prerequisite_id' => $task->id, 'dependent_id' => $next->id]);

    return [$owner, $task, $next];
}

function p3ActiveSince(Item $task, int $secondsAgo): void
{
    $task->update(['is_active' => true]);
    FocusSession::query()->create(['item_id' => $task->id, 'started_at' => now()->subSeconds($secondsAgo)]);
}

test('the suggested task starts from its 2-minute chip and becomes the active one', function () {
    [$owner, $task] = p3BrowserSetup();
    Habit::factory()->for($owner)->create(['name' => 'Dormir 22:00']);
    $this->actingAs($owner);

    $page = visit('/now');

    $page->assertSee('Sugerida para hoy')
        ->assertSee('Captura 10 min')
        ->assertSee('Empezar los 2 minutos: abrir el inbox y escribir una línea')
        ->assertSee('Esto abre:')
        ->assertSee('Hábitos de hoy')
        ->assertSee('Dormir 22:00')
        ->assertDontSee('25 min. ¿Seguís en esto?')
        ->click('button.chip2')
        ->waitForText('En esto desde las')
        ->assertSee('2 min: abrir el inbox y escribir una línea')
        ->assertNoJavascriptErrors();

    expect($task->refresh()->is_active)->toBeTrue();
});

test('the cue appears silently at 25 minutes, survives a reload, is keyboard reachable and never takes focus', function () {
    [$owner, $task] = p3BrowserSetup();
    p3ActiveSince($task, 25 * 60 - 3);
    $this->actingAs($owner);

    $page = visit('/now');

    $page->assertSee('En esto desde las')
        ->assertMissing('[data-testid="focus-cue"]')
        ->assertScript('document.activeElement === document.body', true)
        ->wait(4)
        ->assertPresent('[data-testid="focus-cue"]')
        ->assertSee('25 min. ¿Seguís en esto?')
        ->assertAttribute('[data-testid="focus-cue"]', 'aria-live', 'off')
        ->assertScript('document.querySelector(\'[data-testid="focus-cue"]\').getAttribute("role")', 'group')
        ->assertScript('document.querySelectorAll(\'[role="status"] [data-testid="focus-cue"], [aria-live="polite"] [data-testid="focus-cue"], [aria-live="assertive"] [data-testid="focus-cue"]\').length', 0)
        ->assertScript('document.activeElement === document.body', true)
        ->assertScript('parseFloat(document.querySelector(".cue i").style.width)', 100)
        ->assertScript('Array.from(document.querySelectorAll(\'[data-testid="focus-cue"] button\')).map(b => b.getAttribute("aria-label")).join("|")', 'Sigo: ocultar el aviso hasta el próximo tramo|Terminé: marcar hecho|Estoy trabado: pedir una acción más chica')
        ->assertScript('Array.from(document.querySelectorAll(\'[data-testid="focus-cue"] button\')).every(b => b.tabIndex === 0)', true)
        ->assertScript('document.querySelectorAll("audio, dialog[open], [role=dialog]").length', 0)
        ->refresh()
        ->assertPresent('[data-testid="focus-cue"]')
        ->click('Sigo')
        ->assertMissing('[data-testid="focus-cue"]')
        ->refresh()
        ->assertMissing('[data-testid="focus-cue"]')
        ->assertNoJavascriptErrors();

    expect($task->refresh()->is_active)->toBeTrue();
});

test('a reload after 20 minutes keeps the clock: the line is 80 % full and no cue yet', function () {
    [$owner, $task] = p3BrowserSetup();
    p3ActiveSince($task, 20 * 60);
    $this->actingAs($owner);

    $page = visit('/now');

    $page->assertMissing('[data-testid="focus-cue"]')
        ->assertScript('Math.round(parseFloat(document.querySelector(".cue i").style.width))', 80)
        ->refresh()
        ->assertMissing('[data-testid="focus-cue"]')
        ->assertScript('Math.round(parseFloat(document.querySelector(".cue i").style.width))', 80);
});

test('the cue goes away on its own after its visible window', function () {
    config(['marcos.focus_cue_visible_seconds' => 3]);
    [$owner, $task] = p3BrowserSetup();
    p3ActiveSince($task, 25 * 60);
    $this->actingAs($owner);

    visit('/now')
        ->assertPresent('[data-testid="focus-cue"]')
        ->wait(4)
        ->assertMissing('[data-testid="focus-cue"]');
});

test('"Terminé" in the cue checks the task like "Marcar hecho" and plays the unlock moment', function () {
    [$owner, $task, $next] = p3BrowserSetup();
    p3ActiveSince($task, 25 * 60);
    $this->actingAs($owner);

    $page = visit('/now');

    $page->assertPresent('[data-testid="focus-cue"]')
        ->click('Terminé')
        ->waitForText('Se abrió: Clasificar y elegir prioridad.')
        ->assertSee('Hecho: Captura 10 min')
        ->assertSee('Seguir con la próxima')
        ->assertSee('Cerrar por hoy')
        ->assertAttribute('[data-testid="done-moment"]', 'data-motion', 'full')
        ->assertPresent('[data-testid="done-moment"] .nd.unlock[data-key="DIARIO-2"]')
        ->assertPresent('[data-testid="done-moment"] .nd.unlock[data-key="DIARIO-1"]')
        ->assertScript('getComputedStyle(document.querySelector(\'[data-key="DIARIO-2"] .n-open\')).animationName', 'mos-s02-unlock')
        ->click('Seguir con la próxima')
        ->waitForText('En esto desde las')
        ->assertSee('Clasificar y elegir prioridad')
        ->assertNoJavascriptErrors();

    expect($task->refresh()->completed_at)->not->toBeNull()
        ->and($next->refresh()->is_active)->toBeTrue();
});

test('with reduced motion the unlocked station gets a static highlight instead of the animation', function () {
    [$owner, $task] = p3BrowserSetup();
    p3ActiveSince($task, 60);
    $this->actingAs($owner);

    $page = visit('/now');

    // Playwright here cannot emulate the media feature, so the browser's
    // answer to it is replaced before completing (the page asks at that moment).
    $page->script(<<<'JS'
        void (window.matchMedia = function (query) {
            return {
                matches: String(query).includes('prefers-reduced-motion'),
                media: String(query), onchange: null,
                addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {},
                dispatchEvent() { return false; },
            };
        });
        JS);

    $page->click('[data-testid="mark-done"]')
        ->waitForText('Se abrió: Clasificar y elegir prioridad.')
        ->assertAttribute('[data-testid="done-moment"]', 'data-motion', 'reduce')
        ->assertScript('getComputedStyle(document.querySelector(\'[data-key="DIARIO-2"] .n-open\')).animationName', 'none')
        ->assertScript('getComputedStyle(document.querySelector(\'[data-key="DIARIO-2"] .halo\')).opacity', '1');
});

test('"Estoy trabado" without AI asks for one smaller action and saves it as the new 2-minute version', function () {
    [$owner, $task] = p3BrowserSetup();
    p3ActiveSince($task, 60);
    $this->actingAs($owner);

    $page = visit('/now');

    $page->click('[data-testid="stuck-button"]')
        ->assertPresent('[data-testid="stuck-fallback"]')
        ->assertSee('¿cuál es la acción física más chica que podés hacer ahora?')
        ->assertButtonDisabled('Probar esta')
        ->fill('two_minute_version', 'escribir solo el título de una idea')
        ->click('Probar esta')
        ->waitForText('2 min: escribir solo el título de una idea')
        ->assertMissing('[data-testid="stuck-fallback"]')
        ->assertNoJavascriptErrors();

    $task->refresh();

    expect($task->two_minute_version)->toBe('escribir solo el título de una idea')
        ->and($task->twoMinuteHistory()->first()->text)->toBe('abrir el inbox y escribir una línea')
        ->and($task->twoMinuteHistory()->first()->source)->toBe(TwoMinuteSource::Owner);
});

test('"Estoy trabado" from the cue opens the same stuck flow', function () {
    [$owner, $task] = p3BrowserSetup();
    p3ActiveSince($task, 25 * 60);
    $this->actingAs($owner);

    visit('/now')
        ->assertPresent('[data-testid="focus-cue"]')
        ->click('[data-testid="focus-cue"] button:nth-of-type(3)')
        ->assertPresent('[data-testid="stuck-fallback"]');
});

test('with nothing available the screen invites to capture or open objectives, without blame', function () {
    $this->actingAs(User::factory()->create());

    visit('/now')
        ->assertSee('No hay una marca pintada para hoy.')
        ->assertSee('Capturar una idea')
        ->assertSee('Abrir Objetivos')
        ->assertDontSee('error')
        ->assertNoJavascriptErrors();
});

test('after a login the owner lands on Ahora, with Ahora first in the navigation', function () {
    User::factory()->create(['email' => 'pilot@example.com']);

    visit('/login')
        ->fill('email', 'pilot@example.com')
        ->fill('password', 'password')
        ->press('button[type="submit"]')
        ->assertPathIs('/now')
        ->assertAttribute('nav[aria-label="Principal"] a:first-child', 'aria-current', 'page')
        ->assertSeeIn('nav[aria-label="Principal"] a:first-child', 'Ahora');
});

test('"Cerrar por hoy" after the 2 minutes survives a reload and the task is not re-suggested today', function () {
    [$owner, $task, $next] = p3BrowserSetup();
    $other = Item::factory()->for($task->plan)->create(['title' => 'Otra disponible']);
    p3ActiveSince($task, 120);
    $this->actingAs($owner);

    visit('/now')
        ->click('button.chip2')
        ->click('Cerrar por hoy')
        ->waitForText('Listo por hoy.')
        ->refresh()
        ->assertSee('Listo por hoy.')
        ->click('Ver la próxima')
        ->assertSee('Otra disponible')
        ->assertDontSee('Captura 10 min')
        ->assertNoJavascriptErrors();

    expect($task->refresh()->is_active)->toBeFalse();
});

test('a task active since another day shows the day it started, not a bare time', function () {
    [$owner, $task] = p3BrowserSetup();
    p3ActiveSince($task, 3 * 86400);
    $this->actingAs($owner);

    visit('/now')
        ->assertSee('En esto desde el ')
        ->assertDontSee('En esto desde las')
        ->assertNoJavascriptErrors();
});
