<?php

use App\Actions\Items\StartItem;
use App\Actions\Support\Actor;
use App\Actions\Support\WeeklyMainPriority;
use App\Models\Habit;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Task 3.3 — the Now screen (screen 2, now-focus "The Now Screen Shows One
| Task…"): one task (active or ONE suggestion) with its 2-minute version as
| the primary action, what it unlocks, today's habit checks, and an
| invitation to capture or plan when nothing is available. Never a list of
| tasks, a feed or a pending counter.
*/

function p3NowSetup(): array
{
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario']);
    $plan = Plan::factory()->for($objective)->create(['title' => 'Semana 1']);
    $done = Item::factory()->for($plan)->create(['title' => 'Mesa lista', 'completed_at' => now()->subDay()]);
    $task = Item::factory()->for($plan)->create([
        'title' => 'Captura 10 min',
        'description' => 'Volcar al inbox todo lo que tengo en la cabeza.',
        'two_minute_version' => 'abrir el inbox y escribir una línea',
    ]);
    $next = Item::factory()->for($plan)->create(['title' => 'Clasificar y elegir prioridad']);
    $milestone = Item::factory()->for($plan)->milestone()->create(['title' => 'Semana 1: boceto de Ahora']);
    ItemDependency::query()->create(['prerequisite_id' => $done->id, 'dependent_id' => $task->id]);
    ItemDependency::query()->create(['prerequisite_id' => $task->id, 'dependent_id' => $next->id]);
    ItemDependency::query()->create(['prerequisite_id' => $next->id, 'dependent_id' => $milestone->id]);

    return [$owner, $task, $next, $milestone, $done];
}

test('guests are sent to login from the Now screen', function () {
    $this->get('/now')->assertRedirect(route('login'));
});

test('the active task is shown with its 2-minute version, focus start, what it unlocks and its context', function () {
    Carbon::setTestNow('2026-09-27 15:12:00');
    [$owner, $task] = p3NowSetup();
    app(StartItem::class)(Actor::ownerWeb($owner), $task);

    $this->actingAs($owner)->get(route('now'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('now')
            ->where('now.key', 'DIARIO-2')
            ->where('now.title', 'Captura 10 min')
            ->where('now.description', 'Volcar al inbox todo lo que tengo en la cabeza.')
            ->where('now.two_minute_version', 'abrir el inbox y escribir una línea')
            ->where('now.kind', 'task')
            ->where('now.is_active', true)
            ->where('now.focus_started_at', '2026-09-27T15:12:00+00:00')
            ->where('now.objective', ['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario'])
            ->where('now.milestone', ['key' => 'DIARIO-4', 'title' => 'Semana 1: boceto de Ahora'])
            ->where('now.prerequisite', ['key' => 'DIARIO-1', 'title' => 'Mesa lista', 'state' => 'done'])
            ->where('now.unlocks', [['key' => 'DIARIO-3', 'title' => 'Clasificar y elegir prioridad', 'state' => 'locked']])
            ->where('today', '2026-09-27')
            ->where('server_now', '2026-09-27T15:12:00+00:00')
            ->where('cue', ['minutes' => 25, 'visible_seconds' => 60])
            ->where('restart', false)
            ->where('priority', null)
            ->missing('items')
            ->missing('available')
            ->missing('pending_count'));
});

test('with no active task exactly one suggestion is shown, not active and without a focus clock', function () {
    [$owner, $task] = p3NowSetup();
    Item::factory()->for($task->plan)->count(3)->create();

    $this->actingAs($owner)->get(route('now'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('now.key', 'DIARIO-2')
            ->where('now.is_active', false)
            ->where('now.focus_started_at', null)
            ->has('now.two_minute_version'));
});

test('a milestone as the Now task names itself as the milestone', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    Item::factory()->for(Plan::factory()->for($objective)->create(['title' => 'Semana 1']))->milestone()->create(['title' => 'Semana 1: boceto']);

    $this->actingAs($owner)->get(route('now'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('now.kind', 'milestone')
            ->where('now.milestone', null)
            ->where('now.plan', fn ($plan) => $plan['title'] === 'Semana 1'));
});

test('with nothing available, now is null so the screen invites capture or planning', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    Item::factory()->for(Plan::factory()->for($objective)->create())->create(['completed_at' => now()]);

    $this->actingAs($owner)->get(route('now'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('now')->where('now', null));
});

test('the weekly priority is named when phase 7 provides one', function () {
    [$owner, $task] = p3NowSetup();
    $objective = $task->objective;
    app()->instance(WeeklyMainPriority::class, new class($objective) implements WeeklyMainPriority
    {
        public function __construct(private Objective $objective) {}

        public function currentFor(User $owner): Objective
        {
            return $this->objective;
        }
    });

    $this->actingAs($owner)->get(route('now'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('priority.title', 'Marcos OS en uso diario')
            ->where('priority.url', route('objectives.show', 'DIARIO', absolute: false)));
});

test('today\'s habit checks come in one compact row, with the ones that do not apply today set apart', function () {
    Carbon::setTestNow('2026-09-27 16:00:00'); // Sunday 10:00 in UTC-6
    $owner = User::factory()->create();
    $read = Habit::factory()->for($owner)->create(['name' => 'Leer 2 páginas de Control']);
    Carbon::setTestNow('2026-09-27 14:40:00'); // 08:40 UTC-6
    $read->recordEntry(1);
    Carbon::setTestNow('2026-09-27 16:00:00');
    Habit::factory()->for($owner)->create(['name' => 'Dormir 22:00', 'planned_time' => '22:00']);
    Habit::factory()->for($owner)->quantitative('páginas', 20)->create(['name' => 'Leer libro']);
    Habit::factory()->for($owner)->specificWeekdays([2, 3, 4, 5])->create(['name' => 'Gimnasio 06:30']);
    Habit::factory()->for($owner)->retired()->create(['name' => 'Retirado']);
    Habit::factory()->create(['name' => 'Ajeno']);

    $this->actingAs($owner)->get(route('now'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('habits.scheduled', 3)
            ->where('habits.scheduled.0.name', 'Dormir 22:00')
            ->where('habits.scheduled.0.completed', false)
            ->where('habits.scheduled.0.planned_time', '22:00')
            ->where('habits.scheduled.1.name', 'Leer 2 páginas de Control')
            ->where('habits.scheduled.1.completed', true)
            ->where('habits.scheduled.1.done_at', '2026-09-27T14:40:00+00:00')
            ->where('habits.scheduled.2.name', 'Leer libro')
            ->where('habits.scheduled.2.habit_type', 'quantitative')
            ->where('habits.scheduled.2.accumulated_amount', 0)
            ->where('habits.scheduled.2.daily_target', 20)
            ->has('habits.resting', 1)
            ->where('habits.resting.0.name', 'Gimnasio 06:30')
            ->where('habits.resting.0.next_weekday', 2));
});

test('a retired item or another owner\'s item is never on the Now screen', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    Item::factory()->for(Plan::factory()->for($objective)->create())->create(['retired_at' => now()]);
    $foreign = Objective::factory()->withControlPlan()->create(['key' => 'AJENO']);
    Item::factory()->for(Plan::factory()->for($foreign)->create())->create();

    $this->actingAs($owner)->get(route('now'))
        ->assertInertia(fn (Assert $page) => $page->where('now', null));
});

test('the Now screen does not N+1 with many unlocks and habits', function () {
    [$owner, $task] = p3NowSetup();
    app(StartItem::class)(Actor::ownerWeb($owner), $task);
    Habit::factory()->for($owner)->create();
    $this->actingAs($owner)->get(route('now'))->assertOk();
    $few = mosQueryCount(fn () => $this->actingAs($owner)->get(route('now'))->assertOk());

    foreach (range(1, 4) as $index) {
        $extra = Item::factory()->for($task->plan)->create();
        ItemDependency::query()->create(['prerequisite_id' => $task->id, 'dependent_id' => $extra->id]);
        Habit::factory()->for($owner)->create()->recordEntry(1);
    }

    $many = mosQueryCount(fn () => $this->actingAs($owner)->get(route('now'))->assertOk());

    expect($many)->toBe($few);
});
