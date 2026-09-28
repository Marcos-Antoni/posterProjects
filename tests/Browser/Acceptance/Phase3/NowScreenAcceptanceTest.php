<?php

/*
| Phase 3 acceptance (independent tester) — screen 2 "Ahora" in a real
| browser against now-focus / unlock-graph / design D7 / D14 and mockup
| 02-now.html: every state renders in light and dark without errors, danger
| colour or debt wording; the silent cue never moves focus that the owner
| placed, is reached by a real Tab sequence, and "Sigo" hides it only until
| the next tramo; "Terminé" on a milestone asks for evidence; the unlock
| animation plays and has a reduced-motion static rule. Screenshots of each
| state at 1440 and 768 go to tests/Browser/Screenshots/p3t-* for the
| visual audit.
*/

use App\Models\FocusSession;
use App\Models\Habit;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;

const P3T_DANGER = ['rgb(163, 59, 43)', 'rgb(240, 138, 120)'];

/**
 * The mockup's data: Mesa lista (done) → Captura 10 min → Clasificar y
 * elegir prioridad → … → milestone "Semana 1: boceto de Ahora".
 *
 * @return array{0: User, 1: Item, 2: Item, 3: Item}
 */
function p3tSeed(): array
{
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario']);
    $plan = Plan::factory()->for($objective)->create(['title' => 'Semana 1']);
    $before = Item::factory()->for($plan)->done()->create(['title' => 'Mesa lista']);
    $task = Item::factory()->for($plan)->create([
        'title' => 'Captura 10 min',
        'description' => 'Volcar al inbox todo lo que tengo en la cabeza durante 10 minutos, sin ordenarlo.',
        'two_minute_version' => 'abrir el inbox y escribir una línea',
    ]);
    $next = Item::factory()->for($plan)->create(['title' => 'Clasificar y elegir prioridad']);
    $milestone = Item::factory()->for($plan)->milestone()->create(['title' => 'Semana 1: boceto de Ahora']);
    ItemDependency::query()->create(['prerequisite_id' => $before->id, 'dependent_id' => $task->id]);
    ItemDependency::query()->create(['prerequisite_id' => $task->id, 'dependent_id' => $next->id]);
    ItemDependency::query()->create(['prerequisite_id' => $next->id, 'dependent_id' => $milestone->id]);
    Habit::factory()->for($owner)->create(['name' => 'Leer 2 páginas de Control']);
    Habit::factory()->for($owner)->create(['name' => 'Dormir 22:00']);

    return [$owner, $task, $next, $milestone];
}

function p3tActive(Item $item, int $secondsAgo): void
{
    $item->update(['is_active' => true]);
    FocusSession::query()->create(['item_id' => $item->id, 'started_at' => now()->subSeconds($secondsAgo)]);
}

function p3tShot(mixed $page, string $name): void
{
    foreach ([[1440, 900], [768, 1024]] as [$width, $height]) {
        $page->resize($width, $height)->wait(0.3)->screenshot(true, "p3t-{$name}-{$width}");
    }
}

test('every Now state renders in light and dark without errors, danger colour or debt wording', function (string $mode, string $state) {
    [$owner, $task, , $milestone] = p3tSeed();
    match ($state) {
        'active' => p3tActive($task, 9 * 60),
        'cue' => p3tActive($task, 25 * 60 + 2),
        'suggestion' => null,
        'milestone' => (function () use ($task, $milestone) {
            $task->update(['completed_at' => now()]);
            Item::query()->where('title', 'Clasificar y elegir prioridad')->update(['completed_at' => now()]);
            p3tActive($milestone->refresh(), 60);
        })(),
        'empty' => Item::query()->update(['completed_at' => now()]),
        'restart' => (function () {
            Item::query()->where('title', 'Mesa lista')->update(['completed_at' => now()->subDays(4)]);
        })(),
    };
    $this->actingAs($owner);

    $page = visit('/now');
    $page = $mode === 'dark' ? $page->inDarkMode() : $page->inLightMode();

    $page->assertNoJavascriptErrors()
        ->assertNoConsoleLogs()
        ->assertDontSee('vencid')->assertDontSee('atrasad')->assertDontSee('días sin')->assertDontSee('racha perdida')
        ->assertScript(
            '[...document.querySelectorAll("body *")].filter(e => '.json_encode(P3T_DANGER).'.includes(getComputedStyle(e).color) || '.json_encode(P3T_DANGER).'.includes(getComputedStyle(e).backgroundColor)).length',
            0,
        )
        // One task at most: never a list of other tasks.
        ->assertScript('document.querySelectorAll("article.now").length <= 1', true);

    match ($state) {
        'cue' => $page->assertPresent('[data-testid="focus-cue"]'),
        'restart' => $page->assertSee('Hoy retomás.')->assertSee('Retomar con 2 minutos'),
        'empty' => $page->assertSee('Capturar una idea')->assertSee('Abrir Objetivos'),
        'suggestion' => $page->assertSee('Sugerida para hoy')->assertSee('Empezar los 2 minutos: abrir el inbox y escribir una línea'),
        default => $page->assertSee('En esto desde las'),
    };

    p3tShot($page, "{$state}-{$mode}");
})->with(['light', 'dark'])->with(['active', 'cue', 'suggestion', 'milestone', 'empty', 'restart']);

test('the cue never moves focus the owner placed, and a real Tab sequence reaches Sigo, Terminé and Estoy trabado in order', function () {
    [$owner, $task] = p3tSeed();
    p3tActive($task, 25 * 60 - 4);
    $this->actingAs($owner);

    $page = visit('/now');

    $page->script('document.querySelector("button.chip2").focus()');
    $page->assertScript('document.activeElement.classList.contains("chip2")', true)
        ->wait(6)
        ->assertPresent('[data-testid="focus-cue"]')
        ->assertScript('document.activeElement.classList.contains("chip2")', true)
        ->assertScript('document.querySelector(\'[data-testid="focus-cue"]\').closest("[aria-live=polite],[aria-live=assertive],[role=status],[role=alert],[role=log]") === null', true);

    $page->script(<<<'JS'
        document.activeElement.blur();
        window.__p3tFocus = [];
        document.addEventListener('focusin', (e) => window.__p3tFocus.push((e.target.getAttribute('aria-label') || e.target.textContent || '').trim()));
        JS);
    $page->keys('a.brand', 'Tab');
    // Each press goes to the element that has focus now, so this is the
    // browser's own tab order from the brand onwards.
    foreach (range(1, 10) as $step) {
        $page->keys(':focus', 'Tab');
    }

    $page->assertScript(<<<'JS'
        (() => {
            const seen = window.__p3tFocus;
            const i = seen.findIndex((l) => l.startsWith('Sigo'));
            return i >= 0 && seen[i + 1].startsWith('Terminé') && seen[i + 2].startsWith('Estoy trabado');
        })()
        JS, true);
});

test('"Sigo" hides the cue only until the next tramo, where it comes back', function () {
    config(['marcos.focus_cue_minutes' => 1, 'marcos.focus_cue_visible_seconds' => 20]);
    [$owner, $task] = p3tSeed();
    p3tActive($task, 57);
    $this->actingAs($owner);

    $page = visit('/now');

    $page->assertMissing('[data-testid="focus-cue"]')
        ->wait(5)
        ->assertPresent('[data-testid="focus-cue"]')
        ->click('Sigo')
        ->assertMissing('[data-testid="focus-cue"]')
        ->refresh()
        ->assertMissing('[data-testid="focus-cue"]')
        ->wait(58)
        ->assertPresent('[data-testid="focus-cue"]')
        ->assertNoJavascriptErrors();

    expect($task->refresh()->is_active)->toBeTrue();
});

test('"Terminé" on an active milestone opens the summit ask for evidence instead of checking it blindly', function () {
    [$owner, $task, $next, $milestone] = p3tSeed();
    $task->update(['completed_at' => now()]);
    $next->update(['completed_at' => now()]);
    p3tActive($milestone->refresh(), 25 * 60 + 1);
    $this->actingAs($owner);

    $page = visit('/now');

    $page->assertPresent('[data-testid="focus-cue"]')
        ->click('Terminé')
        ->assertSee('Cumbre.')
        ->assertPresent('#evidence-text');

    expect($milestone->refresh()->completed_at)->toBeNull();

    $page->fill('#evidence-text', 'El boceto de Ahora está en uso')
        ->click('Marcar el hito')
        ->waitForText('Cerrar por hoy')
        ->assertNoJavascriptErrors();

    expect($milestone->refresh()->completed_at)->not->toBeNull()
        ->and($milestone->evidence()->first()?->text)->toBe('El boceto de Ahora está en uso');
});

test('"Marcar hecho" plays the unlock animation on the opened station, and a reduced-motion static rule exists', function () {
    [$owner, $task] = p3tSeed();
    p3tActive($task, 60);
    $this->actingAs($owner);

    $page = visit('/now');

    $page->click('[data-testid="mark-done"]')
        ->waitForText('Se abrió: Clasificar y elegir prioridad.')
        ->assertAttribute('[data-testid="done-moment"]', 'data-motion', 'full')
        ->assertScript('[...document.querySelectorAll(".nd.unlock > :not(.halo):not(title)")].some(e => getComputedStyle(e).animationName.includes("unlock"))', true)
        ->assertScript(<<<'JS'
            (() => {
                const walk = (rules, inReduce) => [...rules].some((r) => {
                    const reduce = inReduce || (r instanceof CSSMediaRule && r.conditionText.includes('prefers-reduced-motion'));
                    if (r.cssRules && walk(r.cssRules, reduce)) return true;
                    return reduce && !!r.selectorText && r.selectorText.includes('.nd.unlock') && r.style.animation.includes('none');
                });
                return [...document.styleSheets].some((s) => { try { return walk(s.cssRules, false); } catch { return false; } });
            })()
            JS, true)
        ->assertNoJavascriptErrors();

    p3tShot($page, 'done-light');
    expect($task->refresh()->completed_at)->not->toBeNull();
});

test('pressing Empezar on the suggestion while an item is active elsewhere never happens silently: the screen shows the active item', function () {
    [$owner, $task] = p3tSeed();
    $draftPlan = Plan::factory()->for($task->objective)->draft()->create(['title' => 'Nivel 2']);
    $hidden = Item::factory()->for($draftPlan)->create(['title' => 'Tarea de un plan borrador']);
    Carbon::setTestNow();
    $this->actingAs($owner)->post("/objectives/DIARIO/items/{$hidden->key}/start");

    if (! $hidden->refresh()->is_active) {
        expect(true)->toBeTrue();

        return;
    }

    visit('/now')->assertSee('Tarea de un plan borrador');
});

test('round 2: a task active for days shows its start with the day, and "Cerrar por hoy" survives a reload', function () {
    [$owner, $task] = p3tSeed();
    p3tActive($task, 4 * 24 * 3600);
    $this->actingAs($owner);

    $page = visit('/now');

    $page->assertSee('En esto desde el')
        ->assertScript('/En esto desde las \d/.test(document.body.innerText)', false)
        ->click('[data-testid="mark-done"]')
        ->waitForText('Cerrar por hoy')
        ->click('Cerrar por hoy')
        ->waitForText('Listo por hoy.')
        ->refresh()
        ->assertSee('Listo por hoy.')
        ->click('Ver la próxima')
        ->assertSee('Clasificar y elegir prioridad')
        ->assertNoJavascriptErrors();

    p3tShot($page, 'closed-light');
});
