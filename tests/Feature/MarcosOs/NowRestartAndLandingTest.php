<?php

use App\Actions\Items\StartItem;
use App\Actions\Support\Actor;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Task 3.7 — a day without activity is followed by a restart offer with a
| 2-minute entry and no missed-day count (now-focus "No Punishment, No Debt…");
| every authenticated landing is the Now screen (auth spec).
*/

function p3RestartOwner(): array
{
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);
    $plan = Plan::factory()->for($objective)->create();

    return [$owner, $plan];
}

test('returning after three days without a check or a habit log offers a restart, without saying how many days', function () {
    [$owner, $plan] = p3RestartOwner();
    Carbon::setTestNow('2026-09-24 18:00:00'); // Thursday 12:00 UTC-6
    Item::factory()->for($plan)->create(['completed_at' => now()]);
    Habit::factory()->for($owner)->create()->recordEntry(1);
    Item::factory()->for($plan)->create(['title' => 'Captura 10 min']);

    Carbon::setTestNow('2026-09-27 18:00:00'); // Sunday

    $response = $this->actingAs($owner)->get(route('now'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('restart', true)
        ->where('now.title', 'Captura 10 min')
        ->has('now.two_minute_version')
        ->missing('missed_days')
        ->missing('days_away'));
    expect(json_encode($response->viewData('page')['props']))->not->toMatch('/\b3 d[ií]as\b|missed/i');
});

test('a single day without activity is already followed by the restart offer', function () {
    [$owner, $plan] = p3RestartOwner();
    Carbon::setTestNow('2026-09-25 20:00:00');
    Item::factory()->for($plan)->create(['completed_at' => now()]);
    Item::factory()->for($plan)->create();

    Carbon::setTestNow('2026-09-27 15:00:00');

    $this->actingAs($owner)->get(route('now'))->assertInertia(fn (Assert $page) => $page->where('restart', true));
});

test('activity yesterday or today means no restart offer', function (string $activity) {
    [$owner, $plan] = p3RestartOwner();
    Carbon::setTestNow('2026-09-20 18:00:00');
    Item::factory()->for($plan)->create(['completed_at' => now()]);
    $task = Item::factory()->for($plan)->create();

    Carbon::setTestNow(match ($activity) {
        'check yesterday', 'habit yesterday' => '2026-09-28 01:00:00', // Sunday 19:00 UTC-6
        default => '2026-09-28 15:00:00',
    });

    match ($activity) {
        'check yesterday', 'check today' => Item::factory()->for($plan)->create(['completed_at' => now()]),
        'habit yesterday', 'habit today' => Habit::factory()->for($owner)->create()->recordEntry(1),
        'start today' => app(StartItem::class)(Actor::ownerWeb($owner), $task),
    };

    Carbon::setTestNow('2026-09-28 16:00:00'); // Monday 10:00 UTC-6

    $this->actingAs($owner)->get(route('now'))->assertInertia(fn (Assert $page) => $page->where('restart', false));
})->with(['check yesterday', 'habit yesterday', 'check today', 'habit today', 'start today']);

test('a late-evening check counts on its UTC-6 day for the restart rule', function () {
    [$owner, $plan] = p3RestartOwner();
    Carbon::setTestNow('2026-09-27 05:30:00'); // Saturday 23:30 UTC-6, already Sunday in UTC
    Item::factory()->for($plan)->create(['completed_at' => now()]);
    Item::factory()->for($plan)->create();

    Carbon::setTestNow('2026-09-28 16:00:00'); // Monday: Saturday was the last day with activity

    $this->actingAs($owner)->get(route('now'))->assertInertia(fn (Assert $page) => $page->where('restart', true));
});

test('an owner with no history at all is not told to restart', function () {
    [$owner, $plan] = p3RestartOwner();
    Item::factory()->for($plan)->create();

    $this->actingAs($owner)->get(route('now'))->assertInertia(fn (Assert $page) => $page->where('restart', false));
});

test('another owner\'s activity never cancels the restart offer', function () {
    [$owner, $plan] = p3RestartOwner();
    Carbon::setTestNow('2026-09-20 18:00:00');
    Item::factory()->for($plan)->create(['completed_at' => now()]);
    Item::factory()->for($plan)->create();
    Carbon::setTestNow('2026-09-27 18:00:00');
    Habit::factory()->create()->recordEntry(1);

    $this->actingAs($owner)->get(route('now'))->assertInertia(fn (Assert $page) => $page->where('restart', true));
});

test('an authenticated visit to the root lands on Now; a guest goes to login', function () {
    $this->get('/')->assertRedirect('/login');

    $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/now');
});

test('an authenticated user asking for the login screen is sent to Now', function () {
    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/now');
});

test('a successful login lands on Now', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/now');
    $this->assertAuthenticatedAs($user);
});
