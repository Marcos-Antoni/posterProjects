<?php

use App\Actions\Items\CheckItem;
use App\Actions\Items\StartItem;
use App\Actions\Support\Actor;
use App\Actions\Support\LastActivity;
use App\Http\Resources\NowView;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\NowDismissal;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Phase 3 round 2 (tester P3-T1, T3, T4, T5): StartItem refuses items Now
| would never show; an active item is always the Now task; "Cerrar por hoy"
| persists for the UTC-6 day; the restart check goes through LastActivity.
*/

function p3r2Owner(): array
{
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);

    return [$owner, $objective, Plan::factory()->for($objective)->create()];
}

test('an item of a draft or retired plan, or of a draft objective, cannot be started', function (string $case) {
    [$owner, $objective] = p3r2Owner();
    $item = match ($case) {
        'draft plan' => Item::factory()->for(Plan::factory()->for($objective)->draft()->create())->create(),
        'retired plan' => Item::factory()->for(Plan::factory()->for($objective)->create(['state' => 'retired']))->create(),
        'draft objective' => Item::factory()->for(Plan::factory()->for(Objective::factory()->for($owner)->draft()->create(['key' => 'BORRADOR']))->create())->create(),
    };

    $errors = mosErrors(fn () => app(StartItem::class)(Actor::ownerWeb($owner), $item));

    expect($errors['item'][0])->toBeString()->not->toBeEmpty()
        ->and($item->refresh()->is_active)->toBeFalse();
})->with(['draft plan', 'retired plan', 'draft objective']);

test('the web start of a draft-plan item answers with the Spanish message', function () {
    [$owner, $objective] = p3r2Owner();
    $item = Item::factory()->for(Plan::factory()->for($objective)->draft()->create())->create();

    $this->actingAs($owner)->from('/now')->post(route('objectives.items.start', ['DIARIO', $item->key]))
        ->assertSessionHasErrors(['item' => 'Esta tarea es de un plan en borrador: activá el plan para empezarla.']);
});

test('an active item is always the Now task, even if its plan or objective is not open', function () {
    [$owner, $objective, $plan] = p3r2Owner();
    Item::factory()->for($plan)->create();
    $hidden = Item::factory()->for(Plan::factory()->for($objective)->draft()->create())->create(['is_active' => true]);

    expect(app(NowView::class)->present($owner))->toMatchArray(['key' => $hidden->key, 'is_active' => true]);
});

test('closing for today persists: the stopped item is not suggested again until tomorrow (UTC-6), but can still be started', function () {
    Carbon::setTestNow('2026-09-27 16:00:00'); // Sunday 10:00 UTC-6
    [$owner, , $plan] = p3r2Owner();
    $first = Item::factory()->for($plan)->create();
    $second = Item::factory()->for($plan)->create();
    app(StartItem::class)(Actor::ownerWeb($owner), $first);

    $this->actingAs($owner)->from('/now')->post(route('now.close-for-today'))->assertRedirect('/now');

    $this->get(route('now'))->assertInertia(fn (Assert $page) => $page
        ->where('now.key', $second->key)
        ->where('closed_today', true));

    Carbon::setTestNow('2026-09-28 05:59:00'); // still Sunday in UTC-6
    expect(app(NowView::class)->select($owner)?->id)->toBe($second->id);

    app(StartItem::class)(Actor::ownerWeb($owner), $first->refresh());
    expect(app(NowView::class)->select($owner)?->id)->toBe($first->id);
    $this->post(route('now.close-for-today'));

    Carbon::setTestNow('2026-09-28 06:01:00'); // Monday in UTC-6
    expect(app(NowView::class)->select($owner)?->id)->toBe($first->id);
    $this->get(route('now'))->assertInertia(fn (Assert $page) => $page->where('closed_today', false));
});

test('closing for today from a suggestion dismisses that suggestion for the day', function () {
    [$owner, , $plan] = p3r2Owner();
    $first = Item::factory()->for($plan)->create();
    $second = Item::factory()->for($plan)->create();

    $this->actingAs($owner)->from('/now')->post(route('now.close-for-today'))->assertRedirect('/now');

    expect($first->refresh()->is_active)->toBeFalse()
        ->and(app(NowView::class)->select($owner)?->id)->toBe($second->id);
});

test('closing for today with nothing to close changes nothing', function () {
    [$owner] = p3r2Owner();

    $this->actingAs($owner)->from('/now')->post(route('now.close-for-today'))->assertRedirect('/now');
    $this->get(route('now'))->assertInertia(fn (Assert $page) => $page->where('closed_today', false));
});

test('another owner\'s dismissal never hides my suggestion', function () {
    [$owner, , $plan] = p3r2Owner();
    $mine = Item::factory()->for($plan)->create();
    $other = User::factory()->create();
    Item::factory()->for(Plan::factory()->for(Objective::factory()->for($other)->withControlPlan()->create(['key' => 'OTRO']))->create())->create();
    $this->actingAs($other)->post(route('now.close-for-today'));

    expect(app(NowView::class)->select($owner)?->id)->toBe($mine->id);
});

test('the restart check reads the last activity through the LastActivity contract', function () {
    Carbon::setTestNow('2026-09-27 18:00:00');
    [$owner, , $plan] = p3r2Owner();
    Item::factory()->for($plan)->create();
    app()->instance(LastActivity::class, new class implements LastActivity
    {
        public function lastActivityAt(User $owner): ?CarbonInterface
        {
            return CarbonImmutable::parse('2026-09-26 20:00:00'); // yesterday UTC-6
        }
    });

    expect(app(NowView::class)->needsRestart($owner))->toBeFalse();

    app()->instance(LastActivity::class, new class implements LastActivity
    {
        public function lastActivityAt(User $owner): ?CarbonInterface
        {
            return CarbonImmutable::parse('2026-09-24 20:00:00');
        }
    });

    expect(app(NowView::class)->needsRestart($owner))->toBeTrue();
});

test('"Cerrar por hoy" targets the item the owner was looking at: after a check it never dismisses the newly unlocked task', function () {
    [$owner, , $plan] = p3r2Owner();
    $done = Item::factory()->for($plan)->create();
    $unlocked = Item::factory()->for($plan)->create();
    ItemDependency::query()->create(['prerequisite_id' => $done->id, 'dependent_id' => $unlocked->id]);
    app(CheckItem::class)(Actor::ownerWeb($owner), $done);

    $this->actingAs($owner)->from('/now')->post(route('now.close-for-today'), ['item' => $done->key])->assertRedirect('/now');

    expect(NowDismissal::query()->pluck('item_id')->all())->toBe([$done->id])
        ->and(app(NowView::class)->select($owner)?->id)->toBe($unlocked->id);
    $this->get(route('now'))->assertInertia(fn (Assert $page) => $page
        ->where('closed_today', true)
        ->where('now.key', $unlocked->key));
});

test('"Cerrar por hoy" with an explicit active item stops and dismisses exactly that item', function () {
    [$owner, , $plan] = p3r2Owner();
    $active = Item::factory()->for($plan)->create();
    $other = Item::factory()->for($plan)->create();
    app(StartItem::class)(Actor::ownerWeb($owner), $active);

    $this->actingAs($owner)->from('/now')->post(route('now.close-for-today'), ['item' => $active->key]);

    expect($active->refresh()->is_active)->toBeFalse()
        ->and(NowDismissal::query()->pluck('item_id')->all())->toBe([$active->id])
        ->and(app(NowView::class)->select($owner)?->id)->toBe($other->id);
});

test('"Cerrar por hoy" refuses another owner\'s or a malformed item key', function (string $key) {
    [$owner] = p3r2Owner();
    Item::factory()->for(Plan::factory()->for(Objective::factory()->withControlPlan()->create(['key' => 'AJENO']))->create())->create();

    $this->actingAs($owner)->post(route('now.close-for-today'), ['item' => $key])->assertNotFound();
})->with(['AJENO-1', 'nada', 'DIARIO-99']);
