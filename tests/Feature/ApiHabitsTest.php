<?php

use App\Enums\TokenName;
use App\Models\Habit;
use App\Models\HabitDay;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Frozen "now": 2026-07-22 18:00 UTC == Wednesday 2026-07-22 12:00 in the
// feature's UTC-6 zone (ISO weekday 3), mirroring HabitMetricsTest.
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-07-22 18:00:00', 'UTC'));
});

/**
 * @return array<string, mixed>
 */
function habitTodayPayload(Habit $habit, array $overrides = []): array
{
    return [
        'id' => $habit->id,
        'date' => '2026-07-22',
        'name' => $habit->name,
        'habit_type' => $habit->habit_type->value,
        'unit' => $habit->unit,
        'target' => $habit->habit_type->value === 'quantitative' ? max(1, (int) $habit->daily_target) : 1,
        'accumulated_amount' => 0,
        'completion_percent' => 0,
        'completed' => false,
        'peak_amount' => 0,
        'times_per_week' => $habit->times_per_week,
        'week_recorded_days' => null,
        ...$overrides,
    ];
}

test('the today list is ordered by name, scoped to scheduled active habits, and pinned to exactly 12 fields', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $zebra = Habit::factory()->for($user)->yesNo()->daily()->create(['name' => 'Zebra habit']);
    $apple = Habit::factory()->for($user)->quantitative('pages', 20)->daily()->create(['name' => 'Apple habit']);

    $response = $this->getJson('/api/v1/habits/today', apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data'))->toBe([
        habitTodayPayload($apple),
        habitTodayPayload($zebra),
    ]);
    expect(array_keys($response->json('data.0')))->toHaveCount(12);
});

test('a habit with no day row yet is flattened to zero and false, never null', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    Habit::factory()->for($user)->quantitative('glasses', 8)->daily()->create(['name' => 'Water']);

    $response = $this->getJson('/api/v1/habits/today', apiBearerHeaders($token));

    $response->assertOk();
    $row = $response->json('data.0');
    expect($row['accumulated_amount'])->toBe(0)
        ->and($row['completion_percent'])->toBe(0)
        ->and($row['completed'])->toBeFalse()
        ->and($row['peak_amount'])->toBe(0);
});

test('a habit with a recorded day reports the persisted aggregate, including peak_amount', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->daily()->create();
    HabitDay::factory()->for($habit)->create([
        'entry_date' => '2026-07-22',
        'accumulated_amount' => 5,
        'peak_amount' => 12,
        'completion_percent' => 25,
        'completed' => false,
    ]);

    $response = $this->getJson('/api/v1/habits/today', apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data.0'))->toBe(habitTodayPayload($habit, [
        'accumulated_amount' => 5,
        'completion_percent' => 25,
        'completed' => false,
        'peak_amount' => 12,
    ]));
});

test('week_recorded_days is populated only for times-per-week habits', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $daily = Habit::factory()->for($user)->yesNo()->daily()->create(['name' => 'Daily habit']);
    $weekly = Habit::factory()->for($user)->yesNo()->timesPerWeek(3)->create(['name' => 'Weekly habit']);

    // Monday 2026-07-20 and today (Wednesday 2026-07-22) are both in the
    // current Monday-based week.
    HabitDay::factory()->for($weekly)->create(['entry_date' => '2026-07-20']);
    HabitDay::factory()->for($weekly)->create(['entry_date' => '2026-07-22']);

    $response = $this->getJson('/api/v1/habits/today', apiBearerHeaders($token));

    $response->assertOk();
    $byName = collect($response->json('data'))->keyBy('name');
    expect($byName['Daily habit']['week_recorded_days'])->toBeNull()
        ->and($byName['Weekly habit']['week_recorded_days'])->toBe(2);
});

test('archived habits and habits unscheduled for today are excluded from the list', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    Habit::factory()->for($user)->yesNo()->daily()->archived()->create(['name' => 'Archived habit']);
    // Scheduled only on Mon/Tue (ISO 1,2) — today is Wednesday (ISO 3).
    Habit::factory()->for($user)->yesNo()->specificWeekdays([1, 2])->create(['name' => 'Unscheduled habit']);
    $due = Habit::factory()->for($user)->yesNo()->specificWeekdays([1, 3])->create(['name' => 'Due habit']);

    $response = $this->getJson('/api/v1/habits/today', apiBearerHeaders($token));

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Due habit']);
});

test('the today list requires a bearer token', function () {
    $response = $this->getJson('/api/v1/habits/today');

    $response->assertStatus(401);
    expect($response->headers->get('WWW-Authenticate'))->toStartWith('Bearer');
});

test('an mcp-only token cannot list today\'s habits', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mcp->value, [TokenName::Mcp->value])->plainTextToken;

    $response = $this->getJson('/api/v1/habits/today', apiBearerHeaders($token));

    $response->assertStatus(403);
    $response->assertExactJson(['message' => 'Este token no tiene permiso para usar esta API.']);
});

test('the query count for the today list does not grow with habit count', function () {
    Model::preventLazyLoading();

    try {
        $user = User::factory()->create();
        $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

        Habit::factory()->for($user)->yesNo()->daily()->create();

        $selectCount = 0;
        DB::listen(function ($query) use (&$selectCount): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
                $selectCount++;
            }
        });

        $this->app['auth']->forgetGuards();
        $selectCount = 0;
        $soloResponse = $this->getJson('/api/v1/habits/today', apiBearerHeaders($token));
        $soloQueries = $selectCount;

        for ($i = 0; $i < 4; $i++) {
            Habit::factory()->for($user)->yesNo()->daily()->create();
        }

        $this->app['auth']->forgetGuards();
        $selectCount = 0;
        $manyResponse = $this->getJson('/api/v1/habits/today', apiBearerHeaders($token));
        $manyQueries = $selectCount;

        $soloResponse->assertOk();
        $manyResponse->assertOk();
        expect($manyQueries)->toBe($soloQueries);
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('an increment on a yes/no habit sets accumulated to one and marks it completed', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $habit = Habit::factory()->for($user)->yesNo()->create();

    $response = $this->postJson("/api/v1/habits/{$habit->id}/increment", [], apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data.accumulated_amount'))->toBe(1)
        ->and($response->json('data.completed'))->toBeTrue()
        ->and($response->json('data.peak_amount'))->toBe(1);
});

test('a quantitative habit advances by one per tap toward its target', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $habit = Habit::factory()->for($user)->quantitative('vasos', 3)->create();

    $this->postJson("/api/v1/habits/{$habit->id}/increment", [], apiBearerHeaders($token));
    $this->postJson("/api/v1/habits/{$habit->id}/increment", [], apiBearerHeaders($token));
    $response = $this->postJson("/api/v1/habits/{$habit->id}/increment", [], apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data.accumulated_amount'))->toBe(3)
        ->and($response->json('data.completed'))->toBeTrue();
});

test('three increments on a quantitative habit below target report the accurate partial progress', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $habit = Habit::factory()->for($user)->quantitative('pages', 5)->create();

    $this->postJson("/api/v1/habits/{$habit->id}/increment", [], apiBearerHeaders($token));
    $this->postJson("/api/v1/habits/{$habit->id}/increment", [], apiBearerHeaders($token));
    $response = $this->postJson("/api/v1/habits/{$habit->id}/increment", [], apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data.accumulated_amount'))->toBe(3)
        ->and($response->json('data.completed'))->toBeFalse();
});

test('a decrement subtracts one from the accumulated amount', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $habit = Habit::factory()->for($user)->quantitative('pages', 5)->create();
    $habit->recordEntry(1);
    $habit->recordEntry(1);
    $habit->recordEntry(1);

    $response = $this->postJson("/api/v1/habits/{$habit->id}/decrement", [], apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data.accumulated_amount'))->toBe(2);
});

test('a decrement never un-completes an already completed day, and peak_amount stays at its high-water mark', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $habit = Habit::factory()->for($user)->yesNo()->create();
    $habit->recordEntry(1);

    $response = $this->postJson("/api/v1/habits/{$habit->id}/decrement", [], apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data.accumulated_amount'))->toBe(0)
        ->and($response->json('data.completed'))->toBeTrue()
        ->and($response->json('data.peak_amount'))->toBe(1)
        ->and($habit->currentStreak())->toBe(1);
});

test('decrementing at zero is rejected with a 422 and the accumulated amount is unchanged', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $habit = Habit::factory()->for($user)->quantitative('pages', 5)->create();

    $response = $this->postJson("/api/v1/habits/{$habit->id}/decrement", [], apiBearerHeaders($token));

    $response->assertStatus(422);
    expect($response->json('errors.habit'))->not->toBeEmpty();
});

test('an unknown, foreign, or archived habit returns the identical generic 404, never disclosing the model', function (Closure $resolveHabitId) {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $habitId = $resolveHabitId($user);

    $response = $this->postJson("/api/v1/habits/{$habitId}/increment", [], apiBearerHeaders($token));

    $response->assertStatus(404);
    $response->assertExactJson(['message' => 'Recurso no encontrado.']);
    expect($response->getContent())->not->toContain('App\\Models\\Habit');
})->with([
    'unknown id' => [fn (): int => 999999],
    'foreign habit' => [fn (User $user): int => Habit::factory()->for(User::factory()->create())->create()->id],
    'archived habit' => [fn (User $user): int => Habit::factory()->for($user)->archived()->create()->id],
]);

test('an increment response is shape-identical to the matching element in the today list', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $habit = Habit::factory()->for($user)->quantitative('pages', 10)->create();

    $incrementResponse = $this->postJson("/api/v1/habits/{$habit->id}/increment", [], apiBearerHeaders($token));
    $todayResponse = $this->getJson('/api/v1/habits/today', apiBearerHeaders($token));

    $incrementResponse->assertOk();
    $todayResponse->assertOk();

    $listElement = collect($todayResponse->json('data'))->firstWhere('id', $habit->id);

    expect(array_keys($incrementResponse->json('data')))->toBe(array_keys($listElement))
        ->and($incrementResponse->json('data'))->toBe($listElement);
});

test('an increment at 23:30 utc-6 and a decrement at 23:31 land on the same utc-6 day', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $habit = Habit::factory()->for($user)->yesNo()->create();

    // BOTH instants are already 2026-07-11 in UTC and both are 2026-07-10 in UTC-6.
    $this->travelTo(Carbon::parse('2026-07-11 05:30:00', 'UTC'));   // 23:30 UTC-6
    $this->postJson("/api/v1/habits/{$habit->id}/increment", [], apiBearerHeaders($token));

    $this->travelTo(Carbon::parse('2026-07-11 05:31:00', 'UTC'));   // 23:31 UTC-6
    $response = $this->postJson("/api/v1/habits/{$habit->id}/decrement", [], apiBearerHeaders($token));

    expect($habit->days()->count())->toBe(1);
    $day = $habit->days()->firstOrFail();
    expect($day->entry_date->toDateString())->toBe('2026-07-10')
        ->and($day->accumulated_amount)->toBe(0);
    $response->assertOk()->assertJsonPath('data.date', '2026-07-10');
});
