<?php

use App\Actions\Habits\LogTwoMinute;
use App\Actions\Items\StartItem;
use App\Actions\Retirement\RetireElement;
use App\Actions\Support\Actor;
use App\Actions\Support\LastActivity;
use App\Enums\PlanState;
use App\Enums\RetirementDecision;
use App\Http\Resources\NowView;
use App\Models\Habit;
use App\Models\HabitDay;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Wave A integration on the Now screen:
| - P3 LastActivity x P4 two-minute logging (P3 NEED): a day where only the
|   habit's 2-minute version was logged counts as showing up, so it never
|   triggers the restart offer.
| - P3 Now selection x P6 retirement: items handed to split-born plans are
|   suggested when the part is active (P6 rule: active only with the source's
|   complete 5-point plan), never while it is a draft; retired items (item,
|   plan or objective retirement) are never the Now task.
*/

function integNowOwner(): array
{
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->withControlPlan()->create(['title' => 'Semana 1']);

    return [$owner, $objective, $plan];
}

function integRetire(User $owner, $element, string $reason, ?RetirementDecision $decision = null, array $payload = [])
{
    return app(RetireElement::class)(Actor::ownerWeb($owner), $element, $reason, $decision, $payload);
}

afterEach(fn () => Carbon::setTestNow());

test('a habit 2-minute log yesterday counts as activity: no restart offer', function () {
    [$owner, , $plan] = integNowOwner();
    Carbon::setTestNow('2026-09-20 18:00:00');
    Item::factory()->for($plan)->create(['completed_at' => now()]);
    $habit = Habit::factory()->for($owner)->create(['created_at' => now()]);

    Carbon::setTestNow('2026-09-26 18:00:00'); // Saturday 12:00 UTC-6
    app(LogTwoMinute::class)(Actor::ownerWeb($owner), $habit);

    Carbon::setTestNow('2026-09-27 18:00:00'); // Sunday

    expect($habit->days()->sole()->two_minute_logged)->toBeTrue()
        ->and($habit->entries()->count())->toBe(0)
        ->and(app(NowView::class)->needsRestart($owner))->toBeFalse();

    $this->actingAs($owner)->get(route('now'))->assertInertia(fn (Assert $page) => $page->where('restart', false));
});

test('an old 2-minute log is still an old activity: the restart offer shows after two quiet days', function () {
    [$owner, , $plan] = integNowOwner();
    Carbon::setTestNow('2026-09-20 18:00:00');
    Item::factory()->for($plan)->create(['completed_at' => now()->subDays(2)]);
    $habit = Habit::factory()->for($owner)->create(['created_at' => now()->subDays(3)]);
    app(LogTwoMinute::class)(Actor::ownerWeb($owner), $habit);

    Carbon::setTestNow('2026-09-24 18:00:00');

    expect(app(NowView::class)->needsRestart($owner))->toBeTrue();
});

test('the 2-minute day counts from its UTC-6 local midnight, whatever time the row was written', function () {
    [$owner] = integNowOwner();
    Carbon::setTestNow('2026-09-27 18:00:00');
    $habit = Habit::factory()->for($owner)->create(['created_at' => now()->subDays(20)]);
    HabitDay::factory()->for($habit)->create(['entry_date' => '2026-09-26', 'two_minute_logged' => true, 'completed' => false, 'accumulated_amount' => 0]);
    HabitDay::factory()->for($habit)->create(['entry_date' => '2026-09-20', 'two_minute_logged' => false]);

    // Saturday 26 00:00 in UTC-6 is 06:00 UTC.
    expect(app(LastActivity::class)->lastActivityAt($owner)?->toIso8601String())->toBe('2026-09-26T06:00:00+00:00');
});

test('items handed to split-born plans are suggested on Now once the part is active', function () {
    [$owner, , $plan] = integNowOwner();
    $first = Item::factory()->for($plan)->create(['title' => 'Mover los muebles']);
    $second = Item::factory()->for($plan)->create(['title' => 'Pintar la pared']);

    $result = integRetire($owner, $plan, 'demasiado grande como plan', RetirementDecision::Split, [
        'parts' => [['title' => 'Semana 1a'], ['title' => 'Semana 1b']],
        'assignments' => [$second->id => 1],
    ]);

    [$partA, $partB] = $result->created->all();

    expect($partA->fresh()->state)->toBe(PlanState::Active)
        ->and($partB->fresh()->state)->toBe(PlanState::Active)
        ->and(app(NowView::class)->select($owner)?->id)->toBe($first->id);

    app(StartItem::class)(Actor::ownerWeb($owner), $first->fresh());

    expect(app(NowView::class)->present($owner))
        ->toMatchArray(['key' => 'DIARIO-1', 'is_active' => true])
        ->and(app(NowView::class)->present($owner)['plan'])->toBe(['id' => $partA->id, 'title' => 'Semana 1a']);

    $this->actingAs($owner)->post(route('objectives.items.check', ['DIARIO', 'DIARIO-1']))->assertRedirect();

    expect(app(NowView::class)->select($owner)?->id)->toBe($second->id);
});

test('a split-born draft part (source without a complete 5-point plan) is not suggested', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();
    Item::factory()->for($plan)->create();

    $result = integRetire($owner, $plan, 'demasiado grande como plan', RetirementDecision::Split, [
        'parts' => [['title' => 'Parte 1'], ['title' => 'Parte 2']],
    ]);

    expect($result->created->map(fn (Plan $part) => $part->fresh()->state)->unique()->all())->toBe([PlanState::Draft])
        ->and(app(NowView::class)->select($owner))->toBeNull();
});

test('retired items are never the Now task: the active one is released, suggestions skip them', function (string $what) {
    [$owner, $objective, $plan] = integNowOwner();
    $active = Item::factory()->for($plan)->create(['title' => 'Activa']);
    app(StartItem::class)(Actor::ownerWeb($owner), $active);

    $element = match ($what) {
        'item' => $active->fresh(),
        'plan' => $plan->fresh(),
        'objective' => $objective->fresh(),
    };
    integRetire($owner, $element, 'ya no es lo que importa ahora', RetirementDecision::ArchiveAsIs);

    expect(Item::withRetired()->findOrFail($active->id)->is_active)->toBeFalse()
        ->and(app(NowView::class)->select($owner))->toBeNull();

    $this->actingAs($owner)->get(route('now'))
        ->assertInertia(fn (Assert $page) => $page->where('now', null))
        ->assertDontSee('Activa');
})->with(['item', 'plan', 'objective']);

test('a retired available item is skipped by the suggestion and the next one is offered', function () {
    [$owner, , $plan] = integNowOwner();
    $first = Item::factory()->for($plan)->create(['title' => 'Primera']);
    $second = Item::factory()->for($plan)->create(['title' => 'Segunda']);

    expect(app(NowView::class)->select($owner)?->id)->toBe($first->id);

    integRetire($owner, $first, 'duplicada con otra tarea');

    expect(app(NowView::class)->select($owner)?->id)->toBe($second->id);
});
