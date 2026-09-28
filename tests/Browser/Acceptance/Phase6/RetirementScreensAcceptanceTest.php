<?php

/*
| Phase 6 acceptance (independent tester) — screens 22 (Retirados) and 23
| (retire dialog) in a real browser: the dialog keeps the action disabled
| with the reason written beside it, never red; decisions per kind; the
| Retired view shows patterns, filters and restore with the blocked reason
| inline; light/dark at 1440 and 768 without JS errors (screenshots for the
| visual audit against the mockups).
*/

use App\Actions\Retirement\RetireElement;
use App\Actions\Support\Actor;
use App\Enums\RetirementDecision;
use App\Models\Habit;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Retirement;
use App\Models\User;
use Illuminate\Support\Carbon;

function p6bSeed(User $owner): array
{
    $diario = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario']);
    $finales = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'FINALES', 'title' => 'Aprobar finales']);
    $semana = Plan::factory()->for($diario)->create(['title' => 'Semana 1']);
    $cerebro = Plan::factory()->for($diario)->create(['title' => 'Diseñar segundo cerebro completo']);
    $fisica = Plan::factory()->for($finales)->create(['title' => 'Física II']);

    $retire = fn ($element, string $reason, ?RetirementDecision $decision = null, array $payload = []) => app(RetireElement::class)(Actor::ownerWeb($owner), $element->fresh(), $reason, $decision, $payload);

    Carbon::setTestNow(Carbon::parse('2026-09-10 12:00', 'America/Guatemala'));
    $guia = Item::factory()->for($fisica)->create(['title' => 'Estudiar toda la guía de Física II en un día']);
    Item::factory()->for($cerebro)->create(['title' => 'Configurar Notion para apuntes']);
    $habit = Habit::factory()->for($owner)->create(['name' => 'Meditar 20 min']);
    Carbon::setTestNow(Carbon::parse('2026-09-12 12:00', 'America/Guatemala'));
    $retire($guia, 'demasiado grande, no arrancaba', RetirementDecision::Split, ['parts' => [
        ['title' => 'Física II: guía resuelta', 'two_minute_version' => 'abrir la guía'],
        ['title' => 'Física II: ejercicios 1 a 5', 'two_minute_version' => 'leer el ejercicio 1'],
    ]]);
    Carbon::setTestNow(Carbon::parse('2026-09-19 12:00', 'America/Guatemala'));
    $retire($cerebro, 'planificar sin práctica', RetirementDecision::ArchiveAsIs);
    $retire($habit, 'demasiado grande para arrancar, volver con 2 minutos');
    Carbon::setTestNow();

    $before = Item::factory()->for($semana)->done()->create(['title' => 'Captura 10 min']);
    $big = Item::factory()->for($semana)->create(['title' => 'Clasificar y elegir prioridad']);
    $after = Item::factory()->for($semana)->create(['title' => 'Revisar video Física']);
    ItemDependency::query()->create(['prerequisite_id' => $before->id, 'dependent_id' => $big->id]);
    ItemDependency::query()->create(['prerequisite_id' => $big->id, 'dependent_id' => $after->id]);

    return compact('diario', 'finales', 'semana', 'fisica', 'big');
}

test('screen 22 renders the patterns, filters and inline blocked restore; screenshots light/dark 1440/768', function (int $width, string $mode) {
    $owner = User::factory()->create();
    p6bSeed($owner);
    $this->actingAs($owner);

    $page = visit('/retired');
    $page = $mode === 'dark' ? $page->inDarkMode() : $page->inLightMode();

    $page->resize($width, 900)
        ->assertSee('Retirados')
        ->assertSee('Lo que se repite, en 3 retiros') // coordinator decision (b): roots only
        ->assertSee('incluye 1 elemento')
        ->assertSee('Edad mediana al retirar')
        ->assertSee('Dividida en “Física II: guía resuelta” y “Física II: ejercicios 1 a 5”')
        ->assertSee('Retirada junto con su plan')
        ->assertSee('Primero devolvé su plan, “Diseñar segundo cerebro completo”.')
        ->assertSee('Sin objetivo')
        ->assertDontSee('fracaso')
        ->assertNoJavascriptErrors()
        ->screenshot(fullPage: true, filename: "p6t-22-retired-{$width}-{$mode}");

    // No horizontal scroll at tablet width.
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
})->with([1440, 768])->with(['light', 'dark']);

test('screen 22: the kind filter narrows the list and restore returns the element to the map', function () {
    $owner = User::factory()->create();
    p6bSeed($owner);
    $this->actingAs($owner);

    $page = visit('/retired')->inLightMode();

    $page->click('button:has-text("Hábitos")')
        ->assertSee('Meditar 20 min')
        ->assertDontSee('Configurar Notion para apuntes')
        ->click('Devolver al mapa')
        ->assertSee('Vuelve a tus hábitos y acepta registros otra vez. Su historial no cambió.')
        ->click('internal:role=dialog >> internal:role=button[name="Devolver al mapa"]')
        ->assertSee('Devuelto al mapa')
        ->assertNoJavascriptErrors();

    expect(Habit::query()->where('name', 'Meditar 20 min')->exists())->toBeTrue()
        ->and(Retirement::query()->whereNull('restored_at')->count())->toBe(3);
});

test('screen 23: the retire action stays disabled with its reason written beside it and is never red; split state screenshots', function (int $width, string $mode) {
    $owner = User::factory()->create();
    p6bSeed($owner);
    $this->actingAs($owner);

    $page = visit('/objectives/DIARIO/items/DIARIO-3');
    $page = $mode === 'dark' ? $page->inDarkMode() : $page->inLightMode();

    $page->resize($width, 900)
        ->click('Retirar')
        ->assertSee('Retirar “Clasificar y elegir prioridad”')
        ->assertSee('Tarea 3 de Marcos OS en uso diario, plan Semana 1. Está disponible.')
        ->assertSee('Razones que ya usaste')
        ->fill('reason', 'no va')
        ->assertSee('Escribí al menos 10 caracteres para que te sirva después. Faltan 5.');

    // The disabled submit really does nothing.
    expect($page->script("document.querySelector('[role=dialog] button[type=submit]').getAttribute('aria-disabled')"))->toBe('true');
    $page->script("document.querySelector('[role=dialog] button[type=submit]').click()");
    $page->wait(0.5);
    expect(Item::query()->where('title', 'Clasificar y elegir prioridad')->exists())->toBeTrue();

    $page->fill('reason', 'demasiado grande: mezcla dos decisiones distintas')
        ->click('Dividir')
        ->assertSee('Las dos heredan su lugar en el camino, en el mismo plan:')
        ->assertSee('Nada se borra.')
        ->assertNoJavascriptErrors()
        ->screenshot(fullPage: true, filename: "p6t-23-split-{$width}-{$mode}");

    $isRed = $page->script(<<<'JS'
        (() => {
            const b = document.querySelector('[role=dialog] button[type=submit]');
            const [r, g, bl] = getComputedStyle(b).backgroundColor.match(/\d+/g).map(Number);
            return r > 150 && r > g * 1.6 && r > bl * 1.6;
        })()
        JS);
    expect($isRed)->toBeFalse();
})->with([1440, 768])->with(['light', 'dark']);

test('screen 23: a plan offers move/split/archive, move lists the other plans; screenshot', function (string $mode) {
    $owner = User::factory()->create();
    $seed = p6bSeed($owner);
    $this->actingAs($owner);
    Plan::factory()->for($seed['finales'])->create(['title' => 'Microeconomía']);
    Item::factory()->for(Plan::factory()->for($seed['finales'])->create(['title' => 'Repaso general']))->count(3)->create();
    $repaso = Plan::query()->where('title', 'Repaso general')->sole();

    $page = visit("/objectives/FINALES/plans/{$repaso->id}");
    $page = $mode === 'dark' ? $page->inDarkMode() : $page->inLightMode();

    $page->resize(1440, 900)
        ->click('Retirar el plan')
        ->assertSee('Retirar el plan “Repaso general”')
        ->assertSee('Mover')->assertSee('Dividir')->assertSee('Archivar tal cual')
        ->assertSee('Sus 3 tareas se retiran con la misma razón.')
        ->fill('reason', 'no va')
        ->click('Mover')
        ->assertSee('Mover sus tareas a')
        ->assertNoJavascriptErrors()
        ->screenshot(fullPage: true, filename: "p6t-23-plan-move-1440-{$mode}");

    $options = $page->script("Array.from(document.querySelectorAll('[role=dialog] select option')).map(o => o.textContent.trim())");
    expect($options)->toContain('Plan Microeconomía')->toContain('Plan Física II')->not->toContain('Plan Repaso general');
})->with(['light', 'dark']);

test('habits manage offers Retirar through the dialog and the habit leaves today', function () {
    $owner = User::factory()->create();
    Habit::factory()->for($owner)->create(['name' => 'Leer 20 páginas']);
    $this->actingAs($owner);

    visit('/habits/manage')->inLightMode()
        ->click('Retirar')
        ->assertSee('Retirar')
        ->fill('reason', 'no encaja en mi mañana')
        ->click('button[type=submit]')
        ->assertPathIs('/habits/manage')
        ->assertDontSee('Leer 20 páginas')
        ->assertNoJavascriptErrors();

    expect(Habit::query()->count())->toBe(0)
        ->and(Habit::withRetired()->count())->toBe(1);
});
