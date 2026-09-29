<?php

use App\Actions\Habits\LogTwoMinute;
use App\Actions\Retirement\RestoreElement;
use App\Actions\Retirement\RetireElement;
use App\Actions\Support\Actor;
use App\Http\Resources\NowHabits;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Habits\ListHabits;
use App\Mcp\Tools\Habits\TodayHabits;
use App\Models\Habit;
use App\Models\Habits\IdentityVotes;
use App\Models\Retirement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Wave A integration (P3 Now x P4 Habits x P6 Retirement): every habit read
| that used `archived_at` (P3 NowHabits, P4 screens/actions/tools) now goes
| through the P6 retirement model — `retired_at` + the NotRetired scope, the
| habits.retire / retired.restore routes and the retire-habit/restore-habit
| MCP tools. Retirement spec: a retired habit is visible only in Retirados.
*/

function integHabitOwner(): array
{
    Carbon::setTestNow('2026-09-27 16:00:00'); // Sunday 10:00 UTC-6
    $owner = User::factory()->create();
    $habit = Habit::factory()->for($owner)->create([
        'name' => 'Meditar veinte',
        'identity_statement' => 'Soy alguien que medita',
        'created_at' => now()->subDays(10),
    ]);

    return [$owner, $habit];
}

function integRetireHabit(User $owner, Habit $habit): Retirement
{
    return app(RetireElement::class)(Actor::ownerWeb($owner), $habit, 'no encaja en mi mañana')->retirement;
}

afterEach(fn () => Carbon::setTestNow());

test('the archived_at column and its archive routes are gone; retire/restore replace them', function () {
    expect(Schema::hasColumn('habits', 'archived_at'))->toBeFalse()
        ->and(Schema::hasColumn('habits', 'retired_at'))->toBeTrue()
        ->and(Route::has('habits.archive'))->toBeFalse()
        ->and(Route::has('habits.unarchive'))->toBeFalse()
        ->and(Route::has('habits.retire'))->toBeTrue()
        ->and(Route::has('retired.restore'))->toBeTrue()
        ->and(method_exists(Habit::class, 'isArchived'))->toBeFalse();
});

test('a retired habit leaves the Now "Hábitos de hoy" row and comes back when restored', function () {
    [$owner, $habit] = integHabitOwner();

    expect(collect(NowHabits::forOwner($owner)['scheduled'])->pluck('name')->all())->toBe(['Meditar veinte']);

    $retirement = integRetireHabit($owner, $habit);

    expect(NowHabits::forOwner($owner))->toBe(['scheduled' => [], 'resting' => []]);
    $this->actingAs($owner)->get(route('now'))
        ->assertInertia(fn (Assert $page) => $page->has('habits.scheduled', 0));

    app(RestoreElement::class)(Actor::ownerWeb($owner), $retirement);

    expect(collect(NowHabits::forOwner($owner)['scheduled'])->pluck('name')->all())->toBe(['Meditar veinte']);
});

test('a retired habit is out of today, manage, identity votes, API and MCP lists, and rejects logging', function () {
    [$owner, $habit] = integHabitOwner();
    integRetireHabit($owner, $habit);

    $this->actingAs($owner)->get(route('habits.today'))
        ->assertInertia(fn (Assert $page) => $page->has('habits', 0)->has('resting', 0));
    $this->actingAs($owner)->get(route('habits.index'))
        ->assertInertia(fn (Assert $page) => $page->has('habits', 0)->where('retired_count', 1)->missing('retired'))
        ->assertDontSee('Meditar veinte');
    $this->actingAs($owner)->get(route('habits.show', $habit->id))->assertNotFound();
    $this->actingAs($owner)->post(route('habits.two-minute.store', $habit->id))->assertNotFound();

    expect(app(IdentityVotes::class)->forUser($owner))->toBe([]);

    $this->getJson('/api/v1/habits/today', mosMobileHeaders($owner))->assertOk()->assertDontSee('Meditar veinte');
    $this->postJson("/api/v1/habits/{$habit->id}/two-minute", [], mosMobileHeaders($owner))->assertNotFound();

    PosterServer::actingAs($owner)->tool(TodayHabits::class)->assertOk()->assertDontSee('Meditar veinte');
    PosterServer::actingAs($owner)->tool(ListHabits::class)->assertOk()->assertDontSee('Meditar veinte');

    $retired = Habit::withRetired()->findOrFail($habit->id);
    $errors = mosErrors(fn () => app(LogTwoMinute::class)(Actor::ownerWeb($owner), $retired));

    expect($errors['habit'][0])->toBe('No podés registrar en un hábito retirado.')
        ->and($retired->days()->count())->toBe(0);
});

test('habit payloads expose retired_at, never archived_at', function () {
    [$owner, $habit] = integHabitOwner();

    $this->actingAs($owner)->get(route('habits.show', $habit->id))
        ->assertInertia(fn (Assert $page) => $page->where('habit.retired_at', null)->missing('habit.archived_at'));

    PosterServer::actingAs($owner)->tool(ListHabits::class)
        ->assertOk()
        ->assertSee('"retired_at":null')
        ->assertDontSee('archived_at');
});

test('the MCP catalog offers retire-habit/restore-habit and no archive tools', function () {
    $owner = User::factory()->create();
    $token = $owner->createToken('mcp')->plainTextToken;

    $names = collect($this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['per_page' => 50],
    ], ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json, text/event-stream'])->assertOk()->json('result.tools'))->pluck('name');

    expect($names)->toContain('retire-habit', 'restore-habit', 'retired-view', 'log-two-minute', 'now-view', 'objective-graph', 'global-graph')
        ->and($names)->not->toContain('archive-habit', 'unarchive-habit');
});
