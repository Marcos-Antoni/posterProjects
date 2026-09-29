<?php

use App\Actions\Retirement\RetireElement;
use App\Actions\Support\Actor;
use App\Enums\RetirementDecision;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Views\RetiredView as RetiredViewTool;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Retirement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Tasks 6.5 / 6.6 — the Retired view (screen 22) and MCP `retired-view`:
| every retired element with kind, title, objective, reason, content decision,
| age at retirement and date; filters by kind, objective and month; counts
| per reason keyword and median age per kind — never a score or a rate.
*/

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->actor = Actor::ownerWeb($this->owner);
    $this->finales = Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'FINALES', 'title' => 'Aprobar finales']);
    $this->web = Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'WEB', 'title' => 'Marcos OS web v1']);
    $this->retire = fn ($element, string $reason, ?RetirementDecision $decision = null, array $payload = []) => app(RetireElement::class)($this->actor, $element, $reason, $decision, $payload);
});

/**
 * Creates at `$createdAt`, retires at `$retiredAt` (UTC-6 dates).
 */
function rvTask(Plan $plan, string $title, string $createdAt): Item
{
    Carbon::setTestNow(Carbon::parse($createdAt.' 12:00', 'America/Guatemala'));
    $item = Item::factory()->for($plan)->create(['title' => $title]);
    Carbon::setTestNow();

    return $item;
}

function rvAt(string $moment, Closure $callback): mixed
{
    Carbon::setTestNow(Carbon::parse($moment.' 12:00', 'America/Guatemala'));

    try {
        return $callback();
    } finally {
        Carbon::setTestNow();
    }
}

test('the Retired view lists every retired element with reason, decision, age and date, newest first', function () {
    $plan = Plan::factory()->for($this->finales)->create(['title' => 'Física II']);
    $physics = rvTask($plan, 'Estudiar toda la guía de Física II en un día', '2026-09-14');
    $calendar = rvTask(Plan::factory()->for($this->web)->create(), 'Migrar calendario', '2026-09-25');
    Carbon::setTestNow(Carbon::parse('2026-08-17 12:00', 'America/Guatemala'));
    $habit = Habit::factory()->for($this->owner)->create(['name' => 'Meditar 20 min']);
    Carbon::setTestNow();

    rvAt('2026-09-16', fn () => ($this->retire)($physics, 'demasiado grande, no arrancaba', RetirementDecision::Split, ['parts' => [
        ['title' => 'Física II: guía resuelta', 'two_minute_version' => 'abrir la guía'],
        ['title' => 'Física II: ejercicios 1 a 5', 'two_minute_version' => 'leer el 1'],
    ]]));
    rvAt('2026-09-26', fn () => ($this->retire)($calendar, 'el calendario sale del producto, no lo uso'));
    rvAt('2026-09-04', fn () => ($this->retire)($habit, 'demasiado grande para arrancar, volver con 2 minutos'));

    $this->actingAs($this->owner)->get('/retired')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('retired/index')
        ->where('view.total', 3)
        ->has('view.months', 1)
        ->where('view.months.0.label', 'Septiembre 2026')
        ->has('view.months.0.entries', 3)
        ->where('view.months.0.entries.0.title', 'Migrar calendario')
        ->where('view.months.0.entries.0.kind', 'task')
        ->where('view.months.0.entries.0.kind_label', 'Tarea')
        ->where('view.months.0.entries.0.objective_label', 'Marcos OS web v1')
        ->where('view.months.0.entries.0.reason', 'el calendario sale del producto, no lo uso')
        ->where('view.months.0.entries.0.decision', 'archive_as_is')
        ->where('view.months.0.entries.0.decision_text', 'Archivada tal cual')
        ->where('view.months.0.entries.0.age_days', 1)
        ->where('view.months.0.entries.0.retired_on', '2026-09-26')
        ->where('view.months.0.entries.1.title', 'Estudiar toda la guía de Física II en un día')
        ->where('view.months.0.entries.1.decision_text', 'Dividida en “Física II: guía resuelta” y “Física II: ejercicios 1 a 5”')
        ->where('view.months.0.entries.1.age_days', 2)
        ->where('view.months.0.entries.2.kind', 'habit')
        ->where('view.months.0.entries.2.kind_label', 'Hábito')
        ->where('view.months.0.entries.2.objective_label', 'Sin objetivo')
        ->where('view.months.0.entries.2.age_days', 18)
        ->where('view.months.0.entries.2.restore.allowed', true));
});

test('filtering by kind lists only that kind, with per-kind counts under the other filters', function () {
    $plan = Plan::factory()->for($this->finales)->create();
    ($this->retire)(Item::factory()->for($plan)->create(['title' => 'Uno']), 'demasiado grande para una sentada');
    ($this->retire)(Item::factory()->for($plan)->create(['title' => 'Dos']), 'planificar sin práctica');
    ($this->retire)(Habit::factory()->for($this->owner)->create(['name' => 'Meditar']), 'demasiado grande para arrancar');

    $this->actingAs($this->owner)->get('/retired?kind=task')->assertInertia(fn (Assert $page) => $page
        ->where('view.filters.kind', 'task')
        ->where('view.total', 2)
        ->has('view.months.0.entries', 2)
        ->where('view.months.0.entries.0.kind', 'task')
        ->where('view.months.0.entries.1.kind', 'task')
        ->where('view.kinds', fn ($kinds) => collect($kinds)->mapWithKeys(fn ($kind) => [$kind['value'] => $kind['count']])->all() === [
            'all' => 3, 'task' => 2, 'milestone' => 0, 'plan' => 0, 'habit' => 1, 'objective' => 0, 'capture' => 0,
        ]));
});

test('filtering by objective and by month narrows the list', function () {
    $finalesPlan = Plan::factory()->for($this->finales)->create();
    $webPlan = Plan::factory()->for($this->web)->create();
    rvAt('2026-08-30', fn () => ($this->retire)(Item::factory()->for($finalesPlan)->create(['title' => 'Agosto']), 'idea suelta, no es para ahora'));
    rvAt('2026-09-12', fn () => ($this->retire)(Item::factory()->for($finalesPlan)->create(['title' => 'Septiembre']), 'demasiado grande para una sentada'));
    rvAt('2026-09-12', fn () => ($this->retire)(Item::factory()->for($webPlan)->create(['title' => 'Web']), 'demasiado grande para una sentada'));

    $this->actingAs($this->owner)->get('/retired?objective=FINALES')->assertInertia(fn (Assert $page) => $page
        ->where('view.total', 2)
        ->has('view.months', 2)
        ->where('view.months.1.label', 'Agosto 2026')
        ->where('view.objectives', fn ($objectives) => collect($objectives)->pluck('key')->sort()->values()->all() === ['FINALES', 'WEB'])
        ->where('view.month_options', fn ($months) => collect($months)->pluck('value')->all() === ['2026-09', '2026-08']));

    $this->actingAs($this->owner)->get('/retired?objective=FINALES&month=2026-09')->assertInertia(fn (Assert $page) => $page
        ->where('view.total', 1)
        ->where('view.months.0.entries.0.title', 'Septiembre'));

    $this->actingAs($this->owner)->get('/retired?month=nope&kind=nope&objective=NOPE')->assertInertia(fn (Assert $page) => $page
        ->where('view.total', 3)
        ->where('view.filters', ['kind' => null, 'objective' => null, 'month' => null]));
});

test('patterns count reason keywords and the median age per kind, never as a score', function () {
    $plan = Plan::factory()->for($this->finales)->create();
    $reasons = ['demasiado grande, no arrancaba', 'demasiado grande para una sentada', 'planificar sin práctica', 'planificar sin práctica', 'el calendario sale del producto'];

    foreach ($reasons as $index => $reason) {
        $item = rvTask($plan, "Tarea {$index}", '2026-09-10');
        rvAt('2026-09-1'.($index + 1), fn () => ($this->retire)($item, $reason));
    }

    $this->actingAs($this->owner)->get('/retired')->assertInertia(fn (Assert $page) => $page
        ->where('view.patterns.reasons', [
            ['keyword' => 'demasiado grande', 'count' => 2],
            ['keyword' => 'planificar sin práctica', 'count' => 2],
        ])
        ->where('view.patterns.other_reasons', 1)
        ->where('view.patterns.median_ages', [
            ['kind' => 'task', 'label' => 'Tareas', 'count' => 5, 'days' => 3],
        ])
        ->where('view.patterns.observation', fn (string $text) => str_contains($text, '3 de 5 tareas se retiraron en sus primeros 3 días')
            && str_contains($text, '“demasiado grande”') && str_contains($text, '“planificar sin práctica”'))
        ->where('view.patterns', fn ($patterns) => preg_match('/%|tasa de|fracaso|puntaje|score/iu', json_encode($patterns, JSON_UNESCAPED_UNICODE)) === 0));
});

test('reasons already used are offered back, most repeated first', function () {
    $plan = Plan::factory()->for($this->finales)->create();
    ($this->retire)(Item::factory()->for($plan)->create(), 'planificar sin práctica');
    ($this->retire)(Item::factory()->for($plan)->create(), 'planificar sin práctica, otra vez');
    ($this->retire)(Item::factory()->for($plan)->create(), 'ya no aplica a nada');

    $this->actingAs($this->owner)->get('/retired')->assertInertia(fn (Assert $page) => $page
        ->where('view.used_reasons', ['planificar sin práctica', 'ya no aplica a nada']));
});

test('an entry whose parent is still retired cannot be restored and says why', function () {
    $plan = Plan::factory()->for($this->finales)->create(['title' => 'Diseñar segundo cerebro completo']);
    Item::factory()->for($plan)->create(['title' => 'Configurar Notion para apuntes']);
    ($this->retire)($plan, 'planificar sin práctica', RetirementDecision::ArchiveAsIs);

    $this->actingAs($this->owner)->get('/retired')->assertInertia(fn (Assert $page) => $page
        ->where('view.total', 2)
        ->where('view.months.0.entries', fn ($entries) => collect($entries)->firstWhere('title', 'Configurar Notion para apuntes')['restore'] === [
            'allowed' => false,
            'blocked_reason' => 'Primero devolvé su plan, “Diseñar segundo cerebro completo”.',
            'summary' => [],
        ] && collect($entries)->firstWhere('title', 'Configurar Notion para apuntes')['decision_text'] === 'Retirada junto con su plan'
          && collect($entries)->firstWhere('title', 'Diseñar segundo cerebro completo')['decision_text'] === 'Archivado tal cual, con su tarea'));

    $child = Retirement::query()->where('retirable_type', 'item')->sole();
    $this->actingAs($this->owner)->post("/retired/{$child->id}/restore")->assertSessionHasErrors('retirement');
});

test('restoring from the view returns the element to the map and flashes where it went', function () {
    $item = Item::factory()->for(Plan::factory()->for($this->finales))->create(['title' => 'Resumen de Micro']);
    $retirement = ($this->retire)($item, 'demasiado grande para una sentada')->retirement;

    $this->actingAs($this->owner)->get('/retired')->assertInertia(fn (Assert $page) => $page
        ->where('view.months.0.entries.0.restore.summary.0', 'Vuelve a Aprobar finales como estaba antes: disponible.'));

    $this->actingAs($this->owner)->post("/retired/{$retirement->id}/restore")
        ->assertRedirect('/retired')
        ->assertInertiaFlash('retirement.message', 'Devuelta al mapa. Está otra vez en Aprobar finales.')
        ->assertInertiaFlash('retirement.url', '/objectives/FINALES/items/'.$item->fresh()->key);

    expect(Item::query()->find($item->id))->not->toBeNull();
    $this->actingAs($this->owner)->get('/retired')->assertInertia(fn (Assert $page) => $page->where('view.total', 0));
});

test('another owner\'s retirement is invisible and cannot be restored', function () {
    $stranger = User::factory()->create();
    $foreign = Item::factory()->create();
    $retirement = app(RetireElement::class)(Actor::ownerWeb($foreign->objective->user), $foreign, 'ya no hace falta esto')->retirement;

    $this->actingAs($stranger)->get('/retired')->assertInertia(fn (Assert $page) => $page->where('view.total', 0));
    $this->actingAs($stranger)->post("/retired/{$retirement->id}/restore")->assertNotFound();
    $this->post('/logout');
    $this->get('/retired')->assertRedirect('/login');
});

test('MCP retired-view returns the same entries and reasons as the web view, with the absolute URL', function () {
    $plan = Plan::factory()->for($this->finales)->create();
    ($this->retire)(Item::factory()->for($plan)->create(['title' => 'Uno']), 'demasiado grande para una sentada');
    ($this->retire)(Habit::factory()->for($this->owner)->create(['name' => 'Meditar']), 'demasiado grande para arrancar');

    $response = PosterServer::actingAs($this->owner)->tool(RetiredViewTool::class, ['kind' => 'task']);

    $response->assertOk()
        ->assertSee('Uno')
        ->assertSee('demasiado grande para una sentada')
        ->assertDontSee('Meditar')
        ->assertSee(url('/retired').'?kind=task');

    expect((new ReflectionClass(RetiredViewTool::class))->getAttributes()[0]->getArguments()[0])->toStartWith('Nivel IA: read');
});

test('reason counts only include what the owner retired; children carried by a plan show under it', function () {
    $plan = Plan::factory()->for($this->finales)->create(['title' => 'Repaso general']);
    Item::factory()->for($plan)->count(2)->create();
    ($this->retire)($plan, 'planificar sin práctica', RetirementDecision::ArchiveAsIs);
    ($this->retire)(Item::factory()->for(Plan::factory()->for($this->finales))->create(), 'planificar sin práctica');

    $this->actingAs($this->owner)->get('/retired')->assertInertia(fn (Assert $page) => $page
        ->where('view.total', 4)
        ->where('view.patterns.total', 2)
        ->where('view.patterns.reasons', [['keyword' => 'planificar sin práctica', 'count' => 2]])
        ->where('view.months.0.entries', fn ($entries) => collect($entries)->firstWhere('title', 'Repaso general')['includes'] === 2
            && collect($entries)->where('is_root', false)->count() === 2));
});
