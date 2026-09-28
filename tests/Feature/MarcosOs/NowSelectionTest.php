<?php

use App\Actions\Items\StartItem;
use App\Actions\Support\Actor;
use App\Actions\Support\NoWeeklyMainPriority;
use App\Actions\Support\WeeklyMainPriority;
use App\Http\Resources\NowView;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
| Task 3.2 — Now selection (now-focus, design D6): the active item if any,
| else the first available item (plan position, item position) of the
| current weekly main priority, else the oldest available item of the first
| active objective (by position) that has one. Never a list.
*/

/**
 * Bind a fixed weekly main priority (Phase 7 owns the real one).
 */
function p3Priority(Objective|Plan|null $priority): void
{
    app()->instance(WeeklyMainPriority::class, new class($priority) implements WeeklyMainPriority
    {
        public function __construct(private Objective|Plan|null $priority) {}

        public function currentFor(User $owner): Objective|Plan|null
        {
            return $this->priority;
        }
    });
}

function p3Objective(User $owner, string $key, int $position = 0, array $attributes = []): Objective
{
    return Objective::factory()->for($owner)->withControlPlan()->create(['key' => $key, 'position' => $position, ...$attributes]);
}

test('without a phase 7 binding there is no weekly main priority', function () {
    expect(app(WeeklyMainPriority::class))->toBeInstanceOf(NoWeeklyMainPriority::class)
        ->and(app(WeeklyMainPriority::class)->currentFor(User::factory()->create()))->toBeNull();
});

test('the active item wins over any suggestion', function () {
    $owner = User::factory()->create();
    $objective = p3Objective($owner, 'DIARIO');
    $plan = Plan::factory()->for($objective)->create();
    Item::factory()->for($plan)->create(['title' => 'Primera']);
    $active = Item::factory()->for($plan)->create(['title' => 'Activa']);
    app(StartItem::class)(Actor::ownerWeb($owner), $active);
    p3Priority($objective);

    expect(app(NowView::class)->select($owner)?->id)->toBe($active->id);
});

test('with no active item, the first available item of the weekly main priority objective is suggested by plan then item position', function () {
    $owner = User::factory()->create();
    $first = p3Objective($owner, 'DIARIO', 0);
    Item::factory()->for(Plan::factory()->for($first)->create())->create(['title' => 'Del primer objetivo']);
    $priority = p3Objective($owner, 'FINALES', 1);
    $late = Plan::factory()->for($priority)->create(['position' => 1]);
    $early = Plan::factory()->for($priority)->create(['position' => 0]);
    Item::factory()->for($late)->create(['title' => 'Plan tardío', 'position' => 0]);
    $done = Item::factory()->for($early)->create(['title' => 'Hecha', 'position' => 0, 'completed_at' => now()]);
    $second = Item::factory()->for($early)->create(['title' => 'Segunda', 'position' => 2]);
    $expected = Item::factory()->for($early)->create(['title' => 'Primera disponible', 'position' => 1]);
    p3Priority($priority);

    expect(app(NowView::class)->select($owner)?->id)->toBe($expected->id);
});

test('a weekly main priority can be a plan', function () {
    $owner = User::factory()->create();
    $objective = p3Objective($owner, 'DIARIO');
    $other = Plan::factory()->for($objective)->create(['position' => 0]);
    Item::factory()->for($other)->create();
    $plan = Plan::factory()->for($objective)->create(['position' => 1]);
    $expected = Item::factory()->for($plan)->create();
    p3Priority($plan);

    expect(app(NowView::class)->select($owner)?->id)->toBe($expected->id);
});

test('locked and retired items of the priority are skipped', function () {
    $owner = User::factory()->create();
    $objective = p3Objective($owner, 'DIARIO');
    $plan = Plan::factory()->for($objective)->create();
    $prerequisite = Item::factory()->for($plan)->create(['position' => 5]);
    $locked = Item::factory()->for($plan)->create(['position' => 0]);
    Item::factory()->for($plan)->create(['position' => 1, 'retired_at' => now()]);
    ItemDependency::query()->create(['prerequisite_id' => $prerequisite->id, 'dependent_id' => $locked->id]);
    p3Priority($objective);

    expect(app(NowView::class)->select($owner)?->id)->toBe($prerequisite->id);
});

test('when the weekly priority has nothing available, the oldest available item of the first active objective is suggested', function () {
    $owner = User::factory()->create();
    $priority = p3Objective($owner, 'FINALES', 2);
    Item::factory()->for(Plan::factory()->for($priority)->create())->create(['completed_at' => now()]);
    $first = p3Objective($owner, 'DIARIO', 0);
    $plan = Plan::factory()->for($first)->create();
    Carbon::setTestNow('2026-09-20 10:00:00');
    $oldest = Item::factory()->for($plan)->create(['position' => 3]);
    Carbon::setTestNow('2026-09-21 10:00:00');
    Item::factory()->for($plan)->create(['position' => 0]);
    Carbon::setTestNow();
    p3Priority($priority);

    expect(app(NowView::class)->select($owner)?->id)->toBe($oldest->id);
});

test('without a weekly priority, the first active objective by position is used, skipping objectives with nothing available', function () {
    $owner = User::factory()->create();
    $empty = p3Objective($owner, 'VACIO', 0);
    Item::factory()->for(Plan::factory()->for($empty)->create())->create(['completed_at' => now()]);
    p3Objective($owner, 'BORRADOR', 1, ['state' => 'draft']);
    $second = p3Objective($owner, 'DIARIO', 2);
    $expected = Item::factory()->for(Plan::factory()->for($second)->create())->create();
    $third = p3Objective($owner, 'FINALES', 3);
    Carbon::setTestNow(now()->subYear());
    Item::factory()->for(Plan::factory()->for($third)->create())->create();
    Carbon::setTestNow();

    expect(app(NowView::class)->select($owner)?->id)->toBe($expected->id);
});

test('closed, draft and retired objectives never provide a suggestion, even as weekly priority', function (string $state) {
    $owner = User::factory()->create();
    $objective = p3Objective($owner, 'DIARIO', 0, ['state' => $state]);
    Item::factory()->for(Plan::factory()->for($objective)->create())->create();
    p3Priority($objective);

    expect(app(NowView::class)->select($owner))->toBeNull();
})->with(['closed', 'draft', 'retired']);

test('another owner\'s items and weekly priority are never suggested', function () {
    $owner = User::factory()->create();
    $foreign = p3Objective(User::factory()->create(), 'AJENO');
    Item::factory()->for(Plan::factory()->for($foreign)->create())->create();
    p3Priority($foreign);

    expect(app(NowView::class)->select($owner))->toBeNull();
});

test('with nothing available at all there is no Now item', function () {
    $owner = User::factory()->create();
    $objective = p3Objective($owner, 'DIARIO');
    $plan = Plan::factory()->for($objective)->create();
    Item::factory()->for($plan)->create(['completed_at' => now()]);
    Item::factory()->for($plan)->create(['retired_at' => now()]);

    expect(app(NowView::class)->select($owner))->toBeNull();
});

test('items of a draft plan are not suggested (the level is not open yet)', function () {
    $owner = User::factory()->create();
    $objective = p3Objective($owner, 'DIARIO');
    Item::factory()->for(Plan::factory()->for($objective)->draft()->create(['position' => 0]))->create();
    $expected = Item::factory()->for(Plan::factory()->for($objective)->create(['position' => 1]))->create();

    expect(app(NowView::class)->select($owner)?->id)->toBe($expected->id);
});

test('the selection runs in a constant number of queries, however many objectives there are', function () {
    $owner = User::factory()->create();
    $objective = p3Objective($owner, 'CUATRO', 9);
    Item::factory()->for(Plan::factory()->for($objective)->create())->create();
    $few = mosQueryCount(fn () => app(NowView::class)->select($owner));

    foreach (['UNO', 'DOS', 'TRES'] as $position => $key) {
        $filled = p3Objective($owner, $key, $position);
        Item::factory()->for(Plan::factory()->for($filled)->create())->count(3)->create(['completed_at' => now()]);
    }

    $many = mosQueryCount(fn () => app(NowView::class)->select($owner));

    expect($many)->toBe($few)->and($few)->toBeLessThanOrEqual(4);
});
