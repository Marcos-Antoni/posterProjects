<?php

use App\Enums\TokenName;
use App\Models\Habit;
use App\Models\Objective;
use App\Models\User;

require_once __DIR__.'/helpers.php';

/*
| Task 4.8 — `GET /api/v1/habits/today` extends to the 17-field shape keeping
| the 12 legacy fields, and `POST /api/v1/habits/{habit}/two-minute` logs the
| 2-minute version (api-habits spec). Today is Sunday 2026-09-27 (UTC-6).
*/

const P4_API_FIELDS = [
    'id', 'date', 'name', 'habit_type', 'unit', 'target', 'accumulated_amount', 'completion_percent', 'completed',
    'peak_amount', 'times_per_week', 'week_recorded_days',
    'two_minute_version', 'shown_up', 'streak_current', 'streak_state', 'objective_key',
];

beforeEach(fn () => p4Today('2026-09-27'));

test('each today element exposes exactly the 17 fields, legacy ones first and unchanged', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create(['key' => 'SALUD']);
    $habit = p4Habit('2026-09-01', [
        'user_id' => $user->id,
        'name' => 'Leer',
        'objective_id' => $objective->id,
        'two_minute_version' => 'Abrir el libro',
    ], fn ($f) => $f->quantitative('páginas', 2));

    $row = $this->getJson(route('api.v1.habits.today'), mosMobileHeaders($user))->assertOk()->json('data.0');

    expect(array_keys($row))->toBe(P4_API_FIELDS)
        ->and($row)->toMatchArray([
            'id' => $habit->id,
            'date' => '2026-09-27',
            'name' => 'Leer',
            'habit_type' => 'quantitative',
            'unit' => 'páginas',
            'target' => 2,
            'accumulated_amount' => 0,
            'completion_percent' => 0,
            'completed' => false,
            'peak_amount' => 0,
            'times_per_week' => null,
            'week_recorded_days' => null,
            'two_minute_version' => 'Abrir el libro',
            'shown_up' => false,
            'objective_key' => 'SALUD',
        ]);
});

test('a single miss reports at_risk with the run intact, not a broken streak', function () {
    $user = User::factory()->create();
    $habit = p4Habit('2026-09-01', ['user_id' => $user->id]);
    p4Days($habit, '2026-09-16', '2026-09-25');

    $row = $this->getJson(route('api.v1.habits.today'), mosMobileHeaders($user))->json('data.0');

    expect($row['streak_current'])->toBe(10)
        ->and($row['streak_state'])->toBe('at_risk')
        ->and($row['objective_key'])->toBeNull();
});

test('two misses report restart and a clean habit reports ok', function () {
    $user = User::factory()->create();
    $broken = p4Habit('2026-09-01', ['user_id' => $user->id, 'name' => 'A']);
    p4Days($broken, '2026-09-15', '2026-09-24');
    p4Habit('2026-09-27', ['user_id' => $user->id, 'name' => 'B']);

    $data = $this->getJson(route('api.v1.habits.today'), mosMobileHeaders($user))->json('data');

    expect($data[0]['streak_state'])->toBe('restart')
        ->and($data[0]['streak_current'])->toBe(0)
        ->and($data[1]['streak_state'])->toBe('ok')
        ->and($data[1]['streak_current'])->toBe(0);
});

test('the 2-minute endpoint shows up without completing and answers the today element', function () {
    $user = User::factory()->create();
    $habit = p4Habit('2026-09-20', ['user_id' => $user->id], fn ($f) => $f->quantitative('páginas', 30));

    $response = $this->postJson(route('api.v1.habits.two-minute', $habit->id), [], mosMobileHeaders($user))->assertOk();

    expect(array_keys($response->json('data')))->toBe(P4_API_FIELDS)
        ->and($response->json('data.shown_up'))->toBeTrue()
        ->and($response->json('data.completed'))->toBeFalse()
        ->and($response->json('data.accumulated_amount'))->toBe(0)
        ->and($response->json('data.streak_current'))->toBe(1);

    $list = $this->getJson(route('api.v1.habits.today'), mosMobileHeaders($user))->json('data.0');

    expect($response->json('data'))->toBe($list);
});

test('a second 2-minute log the same day changes nothing', function () {
    $user = User::factory()->create();
    $habit = p4Habit('2026-09-20', ['user_id' => $user->id]);

    $first = $this->postJson(route('api.v1.habits.two-minute', $habit->id), [], mosMobileHeaders($user))->assertOk()->json('data');
    $second = $this->postJson(route('api.v1.habits.two-minute', $habit->id), [], mosMobileHeaders($user))->assertOk()->json('data');

    expect($second)->toBe($first)
        ->and($habit->days()->count())->toBe(1);
});

test('the 2-minute endpoint lands on the UTC-6 day at 23:30', function () {
    p4Today('2026-09-26', '23:30');
    $user = User::factory()->create();
    $habit = p4Habit('2026-09-20', ['user_id' => $user->id]);

    $this->postJson(route('api.v1.habits.two-minute', $habit->id), [], mosMobileHeaders($user))
        ->assertOk()
        ->assertJsonPath('data.date', '2026-09-26');

    expect($habit->days()->sole()->entry_date->toDateString())->toBe('2026-09-26');
});

test('the 2-minute endpoint shares the non-disclosing 404 set and the mobile ability', function (Closure $habitId) {
    $user = User::factory()->create();
    $id = $habitId($user);

    $response = $this->postJson("/api/v1/habits/{$id}/two-minute", [], mosMobileHeaders($user));

    $response->assertNotFound();
    expect($response->json())->toBe($this->postJson('/api/v1/habits/999999/increment', [], mosMobileHeaders($user))->json());
})->with([
    'unknown' => [fn (User $user) => 999999],
    'foreign' => [fn (User $user) => Habit::factory()->create()->id],
    'archived' => [fn (User $user) => Habit::factory()->for($user)->archived()->create()->id],
]);

test('the 2-minute endpoint requires a mobile token', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->create();

    $this->postJson(route('api.v1.habits.two-minute', $habit->id))->assertUnauthorized();

    $mcp = $user->createToken(TokenName::Mcp->value, [TokenName::Mcp->value])->plainTextToken;

    $this->postJson(route('api.v1.habits.two-minute', $habit->id), [], ['Authorization' => "Bearer {$mcp}"])->assertForbidden();

    expect($habit->days()->count())->toBe(0);
});

test('the today list query count does not grow with habits, days or objectives', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create();
    $first = p4Habit('2026-09-01', ['user_id' => $user->id, 'objective_id' => $objective->id]);
    p4Days($first, '2026-09-10', '2026-09-26');

    $one = mosQueryCount(fn () => $this->getJson(route('api.v1.habits.today'), mosMobileHeaders($user))->assertOk());

    foreach (range(1, 4) as $index) {
        $habit = p4Habit('2026-09-01', ['user_id' => $user->id, 'objective_id' => Objective::factory()->for($user)->create()->id]);
        p4Days($habit, '2026-09-1'.$index, '2026-09-26');
    }

    $five = mosQueryCount(fn () => $this->getJson(route('api.v1.habits.today'), mosMobileHeaders($user))->assertOk());

    expect($five)->toBe($one);
});

test('the two-minute path is documented in openapi with the 17-field element', function () {
    $contract = json_decode(file_get_contents(base_path('openapi/v1.json')), true);

    expect($contract['paths'])->toHaveKey('/api/v1/habits/{habit}/two-minute');

    $operation = $contract['paths']['/api/v1/habits/{habit}/two-minute']['post'];

    expect($operation['security'])->toBe([['bearerAuth' => []]])
        ->and(array_keys($operation['responses']))->toContain(200, 401, 403, 404);

    $schema = $contract['components']['schemas']['TodayHabit'];

    expect(array_keys($schema['properties']))->toEqualCanonicalizing(P4_API_FIELDS)
        ->and($schema['required'])->toEqualCanonicalizing(P4_API_FIELDS)
        ->and($schema['properties']['streak_state']['enum'])->toBe(['ok', 'at_risk', 'restart'])
        ->and($operation['responses']['200']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/TodayHabitResponse');
});
