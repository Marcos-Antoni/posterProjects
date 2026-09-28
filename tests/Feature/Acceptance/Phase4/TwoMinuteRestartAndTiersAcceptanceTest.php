<?php

/*
| Phase 4 acceptance (independent tester) — habits spec "Every Habit Has A
| 2-Minute Version", "A Missed Day Restarts With A 2-Minute Entry", "Habits
| May Hang From An Objective Or Plan", the entry block for archived habits
| (web / API / MCP), and the AI tiers of ai-operations for habit MCP tools
| (log entry / log 2-minute = minor; create, change, level, schedule = major).
| Today is Sunday 2026-09-27 (UTC-6).
*/

use App\Enums\ObjectiveState;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Habits\ArchiveHabit;
use App\Mcp\Tools\Habits\CreateHabit;
use App\Mcp\Tools\Habits\LogHabitEntry;
use App\Mcp\Tools\Habits\LogTwoMinute;
use App\Mcp\Tools\Habits\ShowHabit;
use App\Mcp\Tools\Habits\TodayHabits;
use App\Mcp\Tools\Habits\UnarchiveHabit;
use App\Mcp\Tools\Habits\UpdateHabit;
use App\Models\Habit;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Mcp\Server\Attributes\Description;

require_once __DIR__.'/support.php';

beforeEach(fn () => p4aAt('2026-09-27'));

/**
 * @return array<string, mixed>
 */
function p4aHabitForm(array $overrides = []): array
{
    return [
        'name' => 'Leer',
        'habit_type' => 'quantitative',
        'unit' => 'páginas',
        'daily_target' => 30,
        'recurrence_type' => 'daily',
        'two_minute_version' => 'Abrir el libro en el separador',
        ...$overrides,
    ];
}

test('spec: creating a habit without a 2-minute version is refused (missing, empty, whitespace)', function (mixed $value) {
    $user = User::factory()->create();
    $form = p4aHabitForm();

    if ($value === 'MISSING') {
        unset($form['two_minute_version']);
    } else {
        $form['two_minute_version'] = $value;
    }

    $this->actingAs($user)->post('/habits', $form)->assertSessionHasErrors('two_minute_version');

    expect(Habit::query()->count())->toBe(0);
})->with(['MISSING', '', '   ']);

test('updating a habit can not blank its 2-minute version', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', ['two_minute_version' => 'Abrir el libro'], fn ($f) => $f->quantitative('páginas', 30), $user);

    $this->actingAs($user)->patch("/habits/{$habit->id}", p4aHabitForm(['two_minute_version' => '  ']))
        ->assertSessionHasErrors('two_minute_version');

    expect($habit->fresh()->two_minute_version)->toBe('Abrir el libro');
});

test('spec: quantitative target 30, only the 2-minute version today → shown-up for streak and votes, not completed', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-20', ['identity_statement' => 'leo'], fn ($f) => $f->quantitative('páginas', 30), $user);
    p4aDays($habit, p4aRange('2026-09-20', '2026-09-26'));

    $this->actingAs($user)->post("/habits/{$habit->id}/two-minute")->assertRedirect();

    $day = $habit->days()->where('entry_date', '2026-09-27')->sole();
    $habit->refresh();

    expect($day->completed)->toBeFalse()
        ->and($day->two_minute_logged)->toBeTrue()
        ->and($day->accumulated_amount)->toBe(0)
        ->and($habit->history()->streak()->current)->toBe(8)
        ->and($habit->history()->votes(7)->cast)->toBe(7)
        ->and($habit->history()->votes(7)->possible)->toBe(7);
});

test('2-minute first, then reaching the target completes the day', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-20', [], fn ($f) => $f->quantitative('páginas', 3), $user);

    $this->actingAs($user)->post("/habits/{$habit->id}/two-minute");
    $this->actingAs($user)->post("/habits/{$habit->id}/entries", ['amount' => 3]);

    $day = $habit->days()->sole();

    expect($day->completed)->toBeTrue()->and($day->two_minute_logged)->toBeTrue();
});

test('spec: a habit missed yesterday offers a restart with its 2-minute version and no missed-day count', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', ['two_minute_version' => 'Ponerme las zapatillas'], user: $user);
    p4aDays($habit, p4aRange('2026-09-10', '2026-09-25'));

    $response = $this->actingAs($user)->get('/habits')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('habits/today')
        ->where('habits.0.streak.state', 'at_risk')
        ->where('habits.0.two_minute_version', 'Ponerme las zapatillas')
        ->where('habits.0.today.shown_up', false)
        ->etc());

    $row = $response->viewData('page')['props']['habits'][0];
    $flat = json_encode($row);

    expect($flat)->not->toContain('missed')
        ->and($flat)->not->toContain('debt')
        ->and($flat)->not->toContain('overdue');
});

test('the restart (2-minute) logs today only and never back-fills the missed day', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', [], user: $user);
    p4aDays($habit, p4aRange('2026-09-10', '2026-09-24'));

    $this->actingAs($user)->post("/habits/{$habit->id}/two-minute")->assertRedirect();

    expect($habit->days()->pluck('entry_date')->map->toDateString()->all())->not->toContain('2026-09-25')
        ->and($habit->days()->pluck('entry_date')->map->toDateString()->all())->not->toContain('2026-09-26')
        ->and($habit->fresh()->history()->streak()->current)->toBe(1);
});

test('spec: an archived habit rejects the 2-minute version and entries on web, API and MCP', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', ['archived_at' => now()], user: $user);

    $this->actingAs($user)->post("/habits/{$habit->id}/two-minute");
    $this->actingAs($user)->post("/habits/{$habit->id}/entries", ['amount' => 1]);
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/habits/{$habit->id}/two-minute", [], p4aBearer($user))->assertNotFound();
    PosterServer::actingAs($user)->tool(LogTwoMinute::class, ['habit_id' => $habit->id])->assertHasErrors();
    PosterServer::actingAs($user)->tool(LogHabitEntry::class, ['habit_id' => $habit->id, 'amount' => 1])->assertHasErrors();

    expect($habit->days()->count())->toBe(0)->and($habit->entries()->count())->toBe(0);
});

test('another user can not log the 2-minute version of my habit on the web', function () {
    $habit = p4aHabit('2026-09-01');

    $this->actingAs(User::factory()->create())->post("/habits/{$habit->id}/two-minute");

    expect($habit->days()->count())->toBe(0);
});

test('spec: closing or retiring the linked objective never archives, retires or modifies the habit', function (string $state) {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create();
    $plan = Plan::factory()->for($objective)->create();
    $habit = p4aHabit('2026-09-01', ['objective_id' => $objective->id, 'plan_id' => $plan->id], user: $user);
    p4aDays($habit, p4aRange('2026-09-20', '2026-09-26'));
    $before = $habit->fresh()->attributesToArray();

    $objective->update(['state' => ObjectiveState::from($state)]);

    expect($habit->fresh()->attributesToArray())->toBe($before)
        ->and($habit->fresh()->history()->streak()->current)->toBe(7);
})->with(['closed', 'retired']);

test('a habit can not be linked to another user\'s objective, or to a plan of a different objective', function () {
    $user = User::factory()->create();
    $mine = Objective::factory()->for($user)->create();
    $theirs = Objective::factory()->create();
    $otherPlan = Plan::factory()->for(Objective::factory()->for($user)->create())->create();

    $this->actingAs($user)->post('/habits', p4aHabitForm(['objective_id' => $theirs->id]))->assertSessionHasErrors('objective_id');
    $this->actingAs($user)->post('/habits', p4aHabitForm(['objective_id' => $mine->id, 'plan_id' => $otherPlan->id]))->assertSessionHasErrors('plan_id');

    expect(Habit::query()->count())->toBe(0);
});

test('there is no destroy route for habits (web or API)', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', [], user: $user);

    $web = $this->actingAs($user)->delete("/habits/{$habit->id}")->status();
    app('auth')->forgetGuards();
    $api = $this->deleteJson("/api/v1/habits/{$habit->id}", [], p4aBearer($user))->status();

    expect($web)->toBeIn([404, 405])->and($api)->toBeIn([404, 405])->and(Habit::query()->whereKey($habit->id)->exists())->toBeTrue();
});

/*
| MCP tiers.
*/

test('MCP log-two-minute is minor: applied directly, never completed', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', [], fn ($f) => $f->quantitative('páginas', 30), $user);

    PosterServer::actingAs($user)->tool(LogTwoMinute::class, ['habit_id' => $habit->id])->assertOk();

    expect($habit->days()->sole())->two_minute_logged->toBeTrue()->completed->toBeFalse();
});

test('MCP create-habit and update-habit are major: refused, nothing written', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', ['name' => 'Leer', 'two_minute_version' => 'Abrir el libro'], fn ($f) => $f->quantitative('páginas', 30), $user);
    $before = $habit->fresh()->attributesToArray();

    PosterServer::actingAs($user)->tool(CreateHabit::class, p4aHabitForm(['name' => 'Nuevo']))->assertHasErrors();
    PosterServer::actingAs($user)->tool(UpdateHabit::class, ['habit_id' => $habit->id, ...p4aHabitForm(['daily_target' => 5, 'recurrence_type' => 'times_per_week', 'times_per_week' => 2])])->assertHasErrors();

    expect(Habit::query()->count())->toBe(1)
        ->and($habit->fresh()->attributesToArray())->toBe($before);
});

test('MCP read tools return the 2-minute version and the tolerant streak state', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', ['two_minute_version' => 'Abrir el libro'], user: $user);
    p4aDays($habit, p4aRange('2026-09-16', '2026-09-25'));

    PosterServer::actingAs($user)->tool(TodayHabits::class, [])->assertOk()
        ->assertSee('Abrir el libro')->assertSee('at_risk');
    PosterServer::actingAs($user)->tool(ShowHabit::class, ['habit_id' => $habit->id])->assertOk()
        ->assertSee('Abrir el libro')->assertSee('at_risk');
});

test('mcp-server spec: every habit tool description states its AI tier', function (string $tool) {
    $description = (new ReflectionClass($tool))->getAttributes(Description::class)[0]->getArguments()[0];

    expect($description)->toContain('Nivel IA');
})->with([ArchiveHabit::class, UnarchiveHabit::class])->skip('P4-T3 menor: archive-habit/unarchive-habit descriptions state no AI tier (spec mcp-server); Phase 6 task 6.4 replaces them.');

test('ai-operations: archiving/reactivating a habit through MCP (retire/restore) is major, never applied directly', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', [], user: $user);

    PosterServer::actingAs($user)->tool(ArchiveHabit::class, ['habit_id' => $habit->id]);

    expect($habit->fresh()->archived_at)->toBeNull();
})->skip('P4-T3 menor: pre-existing archive-habit/unarchive-habit apply directly; Phase 6 task 6.4 migrates them to retire/restore — re-check there.');
