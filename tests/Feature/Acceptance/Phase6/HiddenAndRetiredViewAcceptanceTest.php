<?php

/*
| Phase 6 acceptance (independent tester) — spec `retirement` ("Nothing Is
| Deleted", "Retired Elements Are Hidden", "The Retired View Surfaces
| Patterns"), `habits` ("Retiring A Habit Follows The Retirement Protocol"),
| `mcp-server` (retired-view, retired habit rejects entries), `now-focus`
| (a locked item is never active): hidden on every surface, Retired view
| counts/filters, MCP parity, no delete anywhere, relock after restore/move.
*/

use App\Actions\Retirement\RestoreElement;
use App\Actions\Retirement\RetireElement;
use App\Actions\Retirement\RetirementHandlers;
use App\Actions\Retirement\RetirementRecorder;
use App\Actions\Support\Actor;
use App\Enums\ItemState;
use App\Enums\RetirementDecision;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Habits\ListHabits;
use App\Mcp\Tools\Habits\LogHabitEntry;
use App\Mcp\Tools\Habits\ShowHabit;
use App\Mcp\Tools\Habits\TodayHabits;
use App\Mcp\Tools\Items\ShowItem;
use App\Mcp\Tools\Objectives\ListObjectives;
use App\Mcp\Tools\Objectives\ShowObjective;
use App\Mcp\Tools\Views\RetiredView as RetiredViewTool;
use App\Models\ControlMapEntry;
use App\Models\ControlPlan;
use App\Models\Habit;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Retirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

function p6hRetire(Model $element, string $reason, ?RetirementDecision $decision = null, array $payload = []): mixed
{
    $owner = $element instanceof Habit || $element instanceof Objective ? $element->user : $element->objective->user;

    return app(RetireElement::class)(Actor::ownerWeb($owner), $element->fresh(), $reason, $decision, $payload);
}

function p6hItem(Plan $plan, string $title, array $attributes = []): Item
{
    return Item::factory()->for($plan)->create(['title' => $title, ...$attributes])->load('objective');
}

function p6hState(Item $item): ItemState
{
    return Item::withRetired()->withState()->whereKey($item->id)->firstOrFail()->state;
}

/**
 * @return array<string, mixed>
 */
function p6hView(array $query = []): array
{
    $props = test()->get('/retired'.($query === [] ? '' : '?'.http_build_query($query)))->assertOk()->viewData('page')['props'];

    return $props['view'];
}

/**
 * @return list<array<string, mixed>>
 */
function p6hEntries(array $view): array
{
    return array_merge(...array_map(fn (array $month): array => $month['entries'], $view['months'] ?: [['entries' => []]]));
}

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->salud = Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'SALUD', 'title' => 'Correr 10K']);
    $this->plan = Plan::factory()->for($this->salud)->create(['title' => 'Base aeróbica']);
});

// ---------------------------------------------------------------- nothing is deleted

test('no delete, destroy or force-delete route exists for objectives, plans, items or habits', function () {
    $offending = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('DELETE', $route->methods(), true)
            || str_contains((string) $route->getName(), 'destroy') || str_contains((string) $route->getName(), 'force'))
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => preg_match('#^(api/v1/)?(objectives/\{objective\}(/plans/\{plan\}|/items/\{item\})?|habits/\{habit\})$#', $uri) === 1)
        ->values()->all();

    expect($offending)->toBe([]);

    $item = p6hItem($this->plan, 'Uno');
    $habit = Habit::factory()->for($this->owner)->create();
    $this->delete('/objectives/SALUD')->assertMethodNotAllowed();
    $this->delete("/objectives/SALUD/plans/{$this->plan->id}")->assertMethodNotAllowed();
    $this->delete('/objectives/SALUD/items/SALUD-1')->assertMethodNotAllowed();
    $this->delete("/habits/{$habit->id}")->assertMethodNotAllowed();
    $this->post("/habits/{$habit->id}/archive")->assertNotFound();

    expect(Item::query()->find($item->id))->not->toBeNull();
});

test('the MCP server exposes no delete/archive tool for the retirable domain', function () {
    $names = collect((new ReflectionClass(PosterServer::class))->getProperty('tools')->getDefaultValue())
        ->map(fn (string $class) => app($class)->name())->all();

    expect($names)->not->toContain('archive-habit')->not->toContain('unarchive-habit')
        ->and(collect($names)->filter(fn (string $name) => str_contains($name, 'delete'))->all())->toBe([])
        ->and($names)->toContain('retired-view')->toContain('retire-habit')->toContain('restore-habit');
});

test('no hard delete of retirable rows happens during a full retire/restore cycle', function () {
    $a = p6hItem($this->plan, 'A');
    $b = p6hItem($this->plan, 'B');
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);
    $counts = fn () => [Item::withRetired()->count(), Plan::withRetired()->count(), Objective::withRetired()->count(), ItemDependency::query()->count()];
    $before = $counts();

    $result = p6hRetire($this->salud, 'cambié de prioridad este año', RetirementDecision::ArchiveAsIs);
    expect($counts())->toBe($before);

    app(RestoreElement::class)(Actor::ownerWeb($this->owner), $result->retirement);
    expect($counts())->toBe($before)
        ->and(Retirement::query()->count())->toBe(4);
});

// ---------------------------------------------------------------- hidden everywhere

test('a retired item is hidden from the tree, deep link, API and MCP but visible in Retirados', function () {
    p6hItem($this->plan, 'Visible');
    $gone = p6hItem($this->plan, 'Tarea que se fue');
    p6hRetire($gone, 'ya no hace falta esto');

    $this->get('/objectives/SALUD')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('plans.0.items', fn ($items) => collect($items)->pluck('title')->all() === ['Visible']));
    $this->get('/objectives/SALUD/items/SALUD-2')->assertNotFound();
    $this->get('/objectives/SALUD/items/SALUD-2/retire')->assertNotFound();

    $headers = mosMobileHeaders($this->owner);
    $this->getJson('/api/v1/objectives/SALUD', $headers)->assertOk()->assertDontSee('Tarea que se fue');
    $this->getJson('/api/v1/objectives/SALUD/items/SALUD-2', $headers)->assertNotFound();
    $this->postJson('/api/v1/objectives/SALUD/items/SALUD-2/check', [], $headers)->assertNotFound();

    PosterServer::actingAs($this->owner)->tool(ShowObjective::class, ['objective_key' => 'SALUD'])->assertOk()->assertDontSee('Tarea que se fue');
    PosterServer::actingAs($this->owner)->tool(ShowItem::class, ['objective_key' => 'SALUD', 'item_key' => 'SALUD-2'])->assertHasErrors();

    $this->get('/retired')->assertOk()->assertSee('Tarea que se fue');
    PosterServer::actingAs($this->owner)->tool(RetiredViewTool::class)->assertOk()->assertSee('Tarea que se fue');
});

test('a retired plan is hidden from the tree and its URL, and cannot receive new items', function () {
    p6hRetire($this->plan, 'plan que no sirve más', RetirementDecision::ArchiveAsIs);

    $this->get('/objectives/SALUD')->assertOk()->assertInertia(fn (Assert $page) => $page->where('plans', []));
    $this->get("/objectives/SALUD/plans/{$this->plan->id}")->assertNotFound();
    $this->post("/objectives/SALUD/plans/{$this->plan->id}/items", ['kind' => 'task', 'title' => 'Nueva', 'two_minute_version' => 'abrir'])->assertNotFound();

    expect(Item::withRetired()->where('plan_id', $this->plan->id)->count())->toBe(0);
});

test('a retired objective leaves navigation, index, API and MCP lists; its URLs are 404', function () {
    Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'OTRO', 'title' => 'Otro activo']);
    p6hRetire($this->salud, 'cambié de prioridad este año', RetirementDecision::ArchiveAsIs);

    $this->get('/objectives')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('navigationObjectives', [['key' => 'OTRO', 'title' => 'Otro activo']]));
    $this->get('/objectives/SALUD')->assertNotFound();
    $this->get('/objectives/SALUD/retire')->assertNotFound();

    $this->getJson('/api/v1/objectives', mosMobileHeaders($this->owner))->assertOk()->assertDontSee('Correr 10K');
    $this->getJson('/api/v1/objectives/SALUD', mosMobileHeaders($this->owner))->assertNotFound();
    PosterServer::actingAs($this->owner)->tool(ListObjectives::class)->assertOk()->assertDontSee('Correr 10K');
    PosterServer::actingAs($this->owner)->tool(ShowObjective::class, ['objective_key' => 'SALUD'])->assertHasErrors();
});

test('a retired habit is absent from today (web, API, MCP), rejects entries everywhere and keeps its history', function () {
    $habit = Habit::factory()->for($this->owner)->create(['name' => 'Meditar veinte']);
    $habit->recordEntry(1);
    $headers = mosMobileHeaders($this->owner);

    $this->post("/habits/{$habit->id}/retire", ['reason' => 'no encaja en mi mañana'])->assertSessionHasNoErrors()->assertRedirect('/habits/manage');

    expect(Habit::withRetired()->findOrFail($habit->id)->entries()->count())->toBe(1);

    $this->get('/habits')->assertOk()->assertDontSee('Meditar veinte');
    $this->get('/habits/manage')->assertOk()->assertDontSee('Meditar veinte');
    $this->get("/habits/{$habit->id}")->assertNotFound();
    $this->post("/habits/{$habit->id}/entries")->assertNotFound();
    $this->getJson('/api/v1/habits/today', $headers)->assertOk()->assertDontSee('Meditar veinte');
    $this->postJson("/api/v1/habits/{$habit->id}/increment", [], $headers)->assertNotFound();
    PosterServer::actingAs($this->owner)->tool(TodayHabits::class)->assertOk()->assertDontSee('Meditar veinte');
    PosterServer::actingAs($this->owner)->tool(ListHabits::class)->assertOk()->assertDontSee('Meditar veinte');
    PosterServer::actingAs($this->owner)->tool(ShowHabit::class, ['habit_id' => $habit->id])->assertHasErrors();
    PosterServer::actingAs($this->owner)->tool(LogHabitEntry::class, ['habit_id' => $habit->id])->assertHasErrors();

    expect(Habit::withRetired()->findOrFail($habit->id)->entries()->count())->toBe(1);
});

test('the log-habit-entry MCP description no longer points the AI to the removed unarchive-habit tool', function () {
    expect(app(LogHabitEntry::class)->description())->not->toContain('unarchive-habit');
});

// ---------------------------------------------------------------- relock (now-focus invariant)

test('restoring an open prerequisite of an active dependent does not leave a locked item active', function () {
    $a = p6hItem($this->plan, 'A');
    $b = p6hItem($this->plan, 'B');
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    $result = p6hRetire($a, 'ya no hace falta esto', RetirementDecision::ArchiveAsIs);
    $b->update(['is_active' => true]);
    app(RestoreElement::class)(Actor::ownerWeb($this->owner), $result->retirement);

    // now-focus: a locked item MUST NOT be active; Phase 2 UncheckItem releases a relocked active dependent.
    expect(Item::query()->findOrFail($b->id)->is_active)->toBeFalse()
        ->and(p6hState($b))->toBe(ItemState::Locked);
});

test('moving a done item\'s dependents onto an open target does not leave the active dependent active', function () {
    $x = p6hItem($this->plan, 'X', ['completed_at' => now()]);
    $d = p6hItem($this->plan, 'D', ['is_active' => true]);
    p6hItem($this->plan, 'T abierta');
    ItemDependency::query()->create(['prerequisite_id' => $x->id, 'dependent_id' => $d->id]);

    p6hRetire($x, 'lo hago de otra forma', RetirementDecision::Move, ['target' => 'SALUD-3']);

    expect(Item::query()->findOrFail($d->id)->is_active)->toBeFalse()
        ->and(p6hState($d))->toBe(ItemState::Locked);
});

// ---------------------------------------------------------------- Retired view

test('the Retired view filtered by kind "tarea" lists only the tasks with reasons and ages (spec scenario)', function () {
    Carbon::setTestNow('2026-09-01 18:00:00');
    $one = p6hItem($this->plan, 'Tarea uno');
    $two = p6hItem($this->plan, 'Tarea dos');
    $habit = Habit::factory()->for($this->owner)->create(['name' => 'Hábito viejo']);
    Carbon::setTestNow('2026-09-05 18:00:00');
    p6hRetire($one, 'demasiado grande para una sentada');
    p6hRetire($two, 'ya no hace falta, lo resolví');
    p6hRetire($habit, 'no encaja en mi mañana');
    Carbon::setTestNow();

    $view = p6hView(['kind' => 'task']);
    $entries = p6hEntries($view);

    expect(array_column($entries, 'title'))->toEqualCanonicalizing(['Tarea uno', 'Tarea dos'])
        ->and(array_column($entries, 'reason'))->toEqualCanonicalizing(['demasiado grande para una sentada', 'ya no hace falta, lo resolví'])
        ->and(array_column($entries, 'age_days'))->toBe([4, 4])
        ->and(collect($view['kinds'])->firstWhere('value', 'task')['count'])->toBe(2)
        ->and(collect($view['kinds'])->firstWhere('value', 'habit')['count'])->toBe(1)
        ->and(collect($view['kinds'])->firstWhere('value', 'all')['count'])->toBe(3);

    foreach ($entries as $entry) {
        expect($entry)->toHaveKeys(['kind', 'title', 'objective_label', 'reason', 'decision', 'decision_text', 'age_days', 'retired_on']);
    }
});

test('filters by objective and month; a bad filter value is ignored, not an error', function () {
    $otro = Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'OTRO', 'title' => 'Otro']);
    Carbon::setTestNow('2026-08-10 18:00:00');
    p6hRetire(p6hItem($this->plan, 'Agosto salud'), 'demasiado grande para hoy');
    Carbon::setTestNow('2026-09-10 18:00:00');
    p6hRetire(p6hItem(Plan::factory()->for($otro)->create(), 'Septiembre otro'), 'demasiado grande para hoy');
    Carbon::setTestNow();

    expect(array_column(p6hEntries(p6hView(['objective' => 'OTRO'])), 'title'))->toBe(['Septiembre otro'])
        ->and(array_column(p6hEntries(p6hView(['month' => '2026-08'])), 'title'))->toBe(['Agosto salud'])
        ->and(count(p6hEntries(p6hView(['month' => 'garbage', 'kind' => 'nope', 'objective' => 'NADIE']))))->toBe(2);
});

test('the view shows only my retirements, and restored ones leave it', function () {
    $stranger = User::factory()->create();
    $theirs = Objective::factory()->for($stranger)->withControlPlan()->create(['key' => 'AJENO']);
    app(RetireElement::class)(Actor::ownerWeb($stranger), Item::factory()->for(Plan::factory()->for($theirs))->create(['title' => 'Ajena secreta']), 'no es tuya esta razón');
    $mine = p6hItem($this->plan, 'Mía');
    $result = p6hRetire($mine, 'ya no hace falta esto');

    expect(array_column(p6hEntries(p6hView()), 'title'))->toBe(['Mía']);
    $this->get('/retired')->assertDontSee('Ajena secreta')->assertDontSee('no es tuya esta razón');

    $this->post("/retired/{$result->retirement->id}/restore")->assertRedirect('/retired');
    expect(p6hView()['total'])->toBe(0);
});

test('repeated-reason counts only the retirements Marco performed (roots); cascaded children show under their root (coordinator decision b)', function () {
    foreach (['Uno', 'Dos'] as $title) {
        p6hItem($this->plan, $title);
    }
    // One decision, written once, carrying 2 tasks.
    p6hRetire($this->plan, 'planificar sin práctica', RetirementDecision::ArchiveAsIs);
    $other = Plan::factory()->for($this->salud)->create();
    p6hRetire(p6hItem($other, 'X'), 'demasiado grande para una sentada');
    p6hRetire(p6hItem($other, 'Y'), 'demasiado grande, no arrancaba');
    p6hRetire(p6hItem($other, 'Z'), 'idea suelta, no es para ahora');

    $view = p6hView();
    $reasons = collect($view['patterns']['reasons'])->pluck('count', 'keyword')->all();
    $entries = collect(p6hEntries($view));

    expect($reasons)->toBe(['demasiado grande' => 2])
        ->and($view['patterns']['other_reasons'])->toBe(2)
        ->and($view['patterns']['total'])->toBe(4)
        // The children are still listed (restorable history), marked as carried by their root.
        ->and($entries)->toHaveCount(6)
        ->and($entries->firstWhere('title', 'Base aeróbica')['includes'])->toBe(2)
        ->and($entries->where('is_root', false)->pluck('title')->sort()->values()->all())->toBe(['Dos', 'Uno'])
        ->and($view['used_reasons'])->toContain('demasiado grande');
});

test('the patterns are counts and medians, never a score or a failure rate', function () {
    Carbon::setTestNow('2026-09-01 18:00:00');
    $items = collect(['A', 'B', 'C'])->map(fn ($t) => p6hItem($this->plan, $t));
    Carbon::setTestNow('2026-09-03 18:00:00');
    foreach ($items as $item) {
        p6hRetire($item, 'demasiado grande para una sentada');
    }
    Carbon::setTestNow();

    $patterns = p6hView()['patterns'];
    $json = mb_strtolower(json_encode($patterns, JSON_UNESCAPED_UNICODE));

    expect($patterns['median_ages'])->toBe([['kind' => 'task', 'label' => 'Tareas', 'count' => 3, 'days' => 2]])
        ->and($patterns['reasons'][0])->toBe(['keyword' => 'demasiado grande para una sentada', 'count' => 3])
        ->and($json)->not->toContain('%')
        ->not->toContain('fracas')->not->toContain('fall')->not->toContain('score')->not->toContain('puntaje')->not->toContain('tasa');

    $html = mb_strtolower($this->get('/retired')->getContent());
    expect($html)->not->toContain('fracas')->not->toContain('fallaste')->not->toContain('abandonaste');
});

test('MCP retired-view returns the same entries and reasons as the web view', function () {
    p6hRetire(p6hItem($this->plan, 'Uno'), 'demasiado grande para una sentada');
    p6hRetire($this->plan, 'plan que ya no sirve', RetirementDecision::ArchiveAsIs);
    $habit = Habit::factory()->for($this->owner)->create(['name' => 'Leer 20']);
    p6hRetire($habit, 'no encaja en mi mañana');

    $web = p6hEntries(p6hView());
    $response = PosterServer::actingAs($this->owner)->tool(RetiredViewTool::class);
    $response->assertOk();

    foreach ($web as $entry) {
        $response->assertSee($entry['title'])->assertSee($entry['reason']);
    }
    $response->assertSee(url('/retired'));
});

test('the Retired view renders with a fixed number of queries whatever the number of entries', function () {
    $one = function () {
        p6hRetire(p6hItem(Plan::factory()->for($this->salud)->create(), 'Una '.uniqid()), 'demasiado grande para una sentada');
    };
    $one();
    $few = mosQueryCount(fn () => $this->actingAs($this->owner)->get('/retired')->assertOk());

    foreach (range(1, 6) as $i) {
        $one();
        p6hRetire(Habit::factory()->for($this->owner)->create(), 'no encaja en mi mañana');
    }
    $many = mosQueryCount(fn () => $this->actingAs($this->owner)->get('/retired')->assertOk());

    expect($many)->toBeLessThanOrEqual($few + 2);
});

test('the retire dialog context lists move targets without the element or its dependents', function () {
    $x = p6hItem($this->plan, 'X');
    $d = p6hItem($this->plan, 'D');
    p6hItem($this->plan, 'T');
    ItemDependency::query()->create(['prerequisite_id' => $x->id, 'dependent_id' => $d->id]);

    $context = $this->getJson('/objectives/SALUD/items/SALUD-1/retire')->assertOk()->json();

    expect(array_column($context['targets'], 'value'))->toBe(['SALUD-3'])
        ->and($context['default_decision'])->toBeNull()
        ->and(array_column($context['decisions'], 'value'))->toBe(['move', 'split', 'archive_as_is']);
});

// ---------------------------------------------------------------- round 2: relock chains, split state (a)

test('restoring a whole plan whose open item is prerequisite of an active item two levels away releases it', function () {
    $other = Plan::factory()->for($this->salud)->create();
    $a = p6hItem($this->plan, 'A');
    $b = p6hItem($other, 'B', ['completed_at' => now()]);
    $c = p6hItem($other, 'C');
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);
    ItemDependency::query()->create(['prerequisite_id' => $b->id, 'dependent_id' => $c->id]);
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $c->id]);

    $result = p6hRetire($this->plan, 'el plan no sirve más', RetirementDecision::ArchiveAsIs);
    $c->update(['is_active' => true]);
    $c->focusSessions()->create(['started_at' => now()]);

    app(RestoreElement::class)(Actor::ownerWeb($this->owner), $result->retirement->fresh());

    expect(Item::query()->findOrFail($c->id)->is_active)->toBeFalse()
        ->and(p6hState($c))->toBe(ItemState::Locked)
        ->and($c->focusSessions()->sole()->end_reason)->toBe('relocked');
});

test('restoring an objective chain (objective > plans > items) releases an active item of ANOTHER objective that depends on it', function () {
    $otro = Objective::factory()->for($this->owner)->withControlPlan()->create(['key' => 'OTRO']);
    $a = p6hItem($this->plan, 'A');
    $x = p6hItem(Plan::factory()->for($otro)->create(), 'X externa');
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $x->id]);

    $result = p6hRetire($this->salud, 'cambié de prioridad este año', RetirementDecision::ArchiveAsIs);
    $x->update(['is_active' => true]);

    app(RestoreElement::class)(Actor::ownerWeb($this->owner), $result->retirement->fresh());

    expect(Item::query()->findOrFail($x->id)->is_active)->toBeFalse()
        ->and(p6hState($x))->toBe(ItemState::Locked);
});

test('move into an open destination with multi-level dependents releases only the item that became locked', function () {
    $x = p6hItem($this->plan, 'X', ['completed_at' => now()]);
    $d1 = p6hItem($this->plan, 'D1', ['completed_at' => now()]);
    $d2 = p6hItem($this->plan, 'D2', ['is_active' => true]);
    p6hItem($this->plan, 'T abierta');
    ItemDependency::query()->create(['prerequisite_id' => $x->id, 'dependent_id' => $d1->id]);
    ItemDependency::query()->create(['prerequisite_id' => $d1->id, 'dependent_id' => $d2->id]);
    ItemDependency::query()->create(['prerequisite_id' => $x->id, 'dependent_id' => $d2->id]);

    p6hRetire($x, 'lo hago de otra forma', RetirementDecision::Move, ['target' => 'SALUD-4']);

    expect(p6hState($d1))->toBe(ItemState::Done)
        ->and(Item::query()->findOrFail($d2->id)->is_active)->toBeFalse()
        ->and(p6hState($d2))->toBe(ItemState::Locked);
});

test('a move onto a DONE destination keeps the active dependent active (no spurious release)', function () {
    $x = p6hItem($this->plan, 'X', ['completed_at' => now()]);
    $d = p6hItem($this->plan, 'D', ['is_active' => true]);
    p6hItem($this->plan, 'T hecha', ['completed_at' => now()]);
    ItemDependency::query()->create(['prerequisite_id' => $x->id, 'dependent_id' => $d->id]);

    p6hRetire($x, 'lo hago de otra forma', RetirementDecision::Move, ['target' => 'SALUD-3']);

    expect(Item::query()->findOrFail($d->id)->is_active)->toBeTrue()
        ->and(p6hState($d))->toBe(ItemState::Active);
});

test('splitting a done prerequisite into open parts releases its active dependent', function () {
    $x = p6hItem($this->plan, 'X', ['completed_at' => now()]);
    $d = p6hItem($this->plan, 'D', ['is_active' => true]);
    ItemDependency::query()->create(['prerequisite_id' => $x->id, 'dependent_id' => $d->id]);

    p6hRetire($x, 'era demasiado grande', RetirementDecision::Split, ['parts' => [
        ['title' => 'Uno', 'two_minute_version' => 'abrir'],
        ['title' => 'Dos', 'two_minute_version' => 'abrir'],
    ]]);

    expect(Item::query()->findOrFail($d->id)->is_active)->toBeFalse()
        ->and(p6hState($d))->toBe(ItemState::Locked);
});

test('relocking never touches another owner\'s active item', function () {
    $stranger = User::factory()->create();
    $theirs = Objective::factory()->for($stranger)->withControlPlan()->create(['key' => 'AJENO']);
    $tp = Plan::factory()->for($theirs)->create();
    $ta = Item::factory()->for($tp)->create();
    $tb = Item::factory()->for($tp)->create(['is_active' => true]);
    ItemDependency::query()->create(['prerequisite_id' => $ta->id, 'dependent_id' => $tb->id]);
    // Their item is (artificially) active and locked; my retirement must not "fix" it.
    p6hRetire(p6hItem($this->plan, 'Mía'), 'ya no hace falta esto');

    expect($tb->fresh()->is_active)->toBeTrue();
});

test('restoring an already restored retirement or a non-retired element is refused cleanly (422), nothing changes', function () {
    $item = p6hItem($this->plan, 'Uno');
    $result = p6hRetire($item, 'ya no hace falta esto');
    $this->post("/retired/{$result->retirement->id}/restore")->assertSessionHasNoErrors();
    $item->update(['is_active' => true]);

    $this->post("/retired/{$result->retirement->id}/restore")->assertSessionHasErrors('retirement');

    // A stale open row pointing at a live element (e.g. legacy data) must not "restore" it again.
    $ghost = Retirement::factory()->create(['retirable_id' => $item->id, 'user_id' => $this->owner->id, 'objective_id' => $this->salud->id]);
    $this->post("/retired/{$ghost->id}/restore")->assertSessionHasErrors('retirement');

    expect($item->fresh()->is_active)->toBeTrue();
});

/**
 * A plan of SALUD with the given state, control plan (complete|incomplete|none) and two map entries.
 */
function p6hSplitSource(Objective $objective, string $state, string $control): Plan
{
    $plan = Plan::factory()->for($objective)->create(['state' => $state, 'title' => 'Fuente', 'level' => 2]);

    if ($control !== 'none') {
        $factory = ControlPlan::factory()->for($plan, 'plannable');
        ($control === 'incomplete' ? $factory->incomplete() : $factory)->create(['outcome' => 'Correr 10K sin parar', 'risks' => ['lluvia', 'lesión']]);
    }

    ControlMapEntry::factory()->for($plan, 'plannable')->create(['zone' => 'mine', 'text' => 'Salir a las 6', 'position' => 0]);
    ControlMapEntry::factory()->for($plan, 'plannable')->create(['zone' => 'outside', 'text' => 'El clima', 'position' => 1]);

    return $plan;
}

test('refined decision (a): split parts are ACTIVE only when the source was active with a complete control plan, else DRAFT', function (string $state, string $control, string $expected) {
    $source = p6hSplitSource($this->salud, $state, $control);
    $item = p6hItem($source, 'Uno');

    $result = p6hRetire($source, 'mezclaba dos cosas distintas', RetirementDecision::Split, ['parts' => [['title' => 'A'], ['title' => 'B']]]);

    // Round 4 rule: part A gets the task; part B stays empty and an empty part is never active.
    expect($result->created->map(fn ($plan) => $plan->fresh()->state->value)->all())->toBe([$expected, 'draft'])
        ->and($item->fresh()->plan->state->value)->toBe($expected);
})->with([
    'active + complete' => ['active', 'complete', 'active'],
    'active + incomplete' => ['active', 'incomplete', 'draft'],
    'active + none' => ['active', 'none', 'draft'],
    'draft + complete' => ['draft', 'complete', 'draft'],
]);

test('refined decision (a): a DONE source (all tasks done) is not "active": its parts must not come out active', function () {
    $source = p6hSplitSource($this->salud, 'done', 'complete');
    p6hItem($source, 'Hecha', ['completed_at' => now()]);

    $result = p6hRetire($source, 'mezclaba dos cosas distintas', RetirementDecision::Split, ['parts' => [['title' => 'A'], ['title' => 'B']]]);

    // Part A receives the done task (done), part B receives nothing (draft).
    expect($result->created->map(fn ($plan) => $plan->fresh()->state->value)->all())->toBe(['done', 'draft']);
});

test('refined decision (a): each part gets its own deep copy of the 5-point plan and control map', function () {
    $source = p6hSplitSource($this->salud, 'active', 'complete');
    p6hItem($source, 'Uno');

    $result = p6hRetire($source, 'mezclaba dos cosas distintas', RetirementDecision::Split, ['parts' => [['title' => 'A'], ['title' => 'B']]]);
    [$a, $b] = $result->created->map->fresh()->all();

    $sourcePlan = $source->controlPlan()->sole();
    foreach ([$a, $b] as $part) {
        $copy = $part->controlPlan()->sole();
        expect($copy->id)->not->toBe($sourcePlan->id)
            ->and($copy->only(['outcome', 'metric_name', 'metric_target', 'risks', 'contingency']))->toBe($sourcePlan->only(['outcome', 'metric_name', 'metric_target', 'risks', 'contingency']))
            ->and($copy->deadline->toDateString())->toBe($sourcePlan->deadline->toDateString())
            ->and($part->controlMapEntries()->orderBy('position')->get()->map->only(['zone', 'text'])->map(fn ($e) => [$e['zone']->value ?? $e['zone'], $e['text']])->all())
            ->toBe([['mine', 'Salir a las 6'], ['outside', 'El clima']])
            ->and($part->level)->toBe(2);
    }

    // Edits to a copy never reach the source nor the sibling.
    $a->controlPlan()->sole()->update(['outcome' => 'Solo la parte A']);
    $a->controlMapEntries()->first()->update(['text' => 'Cambiado en A']);

    expect($sourcePlan->fresh()->outcome)->toBe('Correr 10K sin parar')
        ->and($b->controlPlan()->sole()->outcome)->toBe('Correr 10K sin parar')
        ->and($source->controlMapEntries()->pluck('text')->all())->not->toContain('Cambiado en A')
        ->and($b->controlMapEntries()->pluck('text')->all())->not->toContain('Cambiado en A')
        ->and(ControlPlan::query()->where('plannable_type', 'plan')->count())->toBe(3)
        ->and(ControlMapEntry::query()->where('plannable_type', 'plan')->count())->toBe(6);
});

test('refined decision (a): a failure after the copies rolls them back too', function () {
    $source = p6hSplitSource($this->salud, 'active', 'complete');
    p6hItem($source, 'Uno');
    app()->bind(RetirementRecorder::class, fn ($app) => new class($app->make(RetirementHandlers::class)) extends RetirementRecorder
    {
        public function record(Model $element, string $reason, RetirementDecision $decision, ?array $payload = null, ?Retirement $parent = null): Retirement
        {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => p6hRetire($source, 'mezclaba dos cosas distintas', RetirementDecision::Split, ['parts' => [['title' => 'A'], ['title' => 'B']]]))->toThrow(RuntimeException::class);

    expect(Plan::withRetired()->count())->toBe(2)
        ->and(ControlPlan::query()->where('plannable_type', 'plan')->count())->toBe(1)
        ->and(ControlMapEntry::query()->where('plannable_type', 'plan')->count())->toBe(2);
});

test('refined decision (a): a split part can itself be retired and restored with its copied control plan intact, and the source restores with its own', function () {
    $source = p6hSplitSource($this->salud, 'active', 'complete');
    $item = p6hItem($source, 'Uno');
    $split = p6hRetire($source, 'mezclaba dos cosas distintas', RetirementDecision::Split, ['parts' => [['title' => 'A'], ['title' => 'B']]]);
    [$a] = $split->created->all();

    $partRetirement = p6hRetire($a->fresh(), 'la parte A tampoco sirve', RetirementDecision::ArchiveAsIs)->retirement;
    expect(Item::query()->find($item->id))->toBeNull();

    app(RestoreElement::class)(Actor::ownerWeb($this->owner), $partRetirement->fresh());
    $a = Plan::query()->findOrFail($a->id);
    expect($a->state->value)->toBe('active')
        ->and($a->controlPlan()->sole()->outcome)->toBe('Correr 10K sin parar')
        ->and(p6hState($item))->toBe(ItemState::Available);

    app(RestoreElement::class)(Actor::ownerWeb($this->owner), $split->retirement->fresh());
    expect(Plan::query()->findOrFail($source->id)->state->value)->toBe('active')
        ->and($source->controlPlan()->sole()->outcome)->toBe('Correr 10K sin parar');

    $this->get('/objectives/SALUD/plans/'.$a->id)->assertOk()->assertSee('Correr 10K sin parar');
});

test('refined decision (a): tasks moved into an ACTIVE part stay actionable in the tree; into a DRAFT part they sit in a draft plan', function (string $control, string $expected) {
    $source = p6hSplitSource($this->salud, 'active', $control);
    $item = p6hItem($source, 'Seguir corriendo');

    p6hRetire($source, 'mezclaba dos cosas distintas', RetirementDecision::Split, ['parts' => [['title' => 'A'], ['title' => 'B']]]);

    $this->get('/objectives/SALUD')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('plans', fn ($plans) => collect($plans)->contains(fn ($p) => $p['state'] === $expected
            && collect($p['items'])->pluck('title')->contains('Seguir corriendo'))));
    expect(p6hState($item))->toBe(ItemState::Available);
})->with([
    'complete' => ['complete', 'active'],
    'incomplete' => ['incomplete', 'draft'],
]);
