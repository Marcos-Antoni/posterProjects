<?php

/*
| Phase 4 acceptance (independent tester) — api-habits delta: the today list
| keeps the 12 legacy fields (names, types, semantics) an unmodified
| posterMobile build parses strictly, plus the 5 Marcos OS fields; the
| taps and the new `two-minute` endpoint answer the exact list element;
| `two-minute` is idempotent within the day, shares the non-disclosing 404
| set and the `mobile` ability boundary; OpenAPI documents it. Today is
| Sunday 2026-09-27 (UTC-6).
*/

use App\Models\Objective;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support.php';

beforeEach(fn () => p4aAt('2026-09-27', '10:00'));

const P4A_LEGACY_FIELDS = ['id', 'date', 'name', 'habit_type', 'unit', 'target', 'accumulated_amount', 'completion_percent', 'completed', 'peak_amount', 'times_per_week', 'week_recorded_days'];
const P4A_NEW_FIELDS = ['two_minute_version', 'shown_up', 'streak_current', 'streak_state', 'objective_key'];

/**
 * posterMobile's strict reader (lib/data/remote/dio_habits_gateway.dart
 * `_todayHabitFrom`): jsonInt / jsonString / jsonBool refuse null, the
 * nullable readers accept null or the type; `date` is parsed as a timestamp.
 *
 * @param  array<string, mixed>  $e
 */
function p4aAssertMobileParses(array $e): void
{
    expect($e['id'])->toBeInt()
        ->and($e['date'])->toBeString()->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($e['name'])->toBeString()
        ->and($e['habit_type'])->toBeIn(['yes_no', 'quantitative'])
        ->and($e['unit'] === null || is_string($e['unit']))->toBeTrue()
        ->and($e['target'])->toBeInt()
        ->and($e['accumulated_amount'])->toBeInt()
        ->and($e['completion_percent'])->toBeInt()
        ->and($e['completed'])->toBeBool()
        ->and($e['peak_amount'])->toBeInt()
        ->and($e['times_per_week'] === null || is_int($e['times_per_week']))->toBeTrue()
        ->and($e['week_recorded_days'] === null || is_int($e['week_recorded_days']))->toBeTrue();
}

test('every element has exactly the 12 legacy + 5 new keys, legacy ones parse with posterMobile\'s strict reader', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create(['key' => 'SALUD']);
    $habits = [
        p4aHabit('2026-09-01', ['name' => 'A yes/no daily'], fn ($f) => $f->yesNo()->daily(), $user),
        p4aHabit('2026-09-01', ['name' => 'B quantitative', 'objective_id' => $objective->id], fn ($f) => $f->quantitative('páginas', 8), $user),
        p4aHabit('2026-09-01', ['name' => 'C weekly'], fn ($f) => $f->timesPerWeek(3), $user),
        p4aHabit('2026-09-01', ['name' => 'D sunday'], fn ($f) => $f->specificWeekdays([7]), $user),
    ];
    p4aDay($habits[1], '2026-09-27', 'partial');
    p4aDay($habits[2], '2026-09-21');

    $data = $this->getJson('/api/v1/habits/today', p4aBearer($user))->assertOk()->json('data');

    expect($data)->toHaveCount(4);

    foreach ($data as $element) {
        expect(array_keys($element))->toEqualCanonicalizing([...P4A_LEGACY_FIELDS, ...P4A_NEW_FIELDS])
            ->and(array_slice(array_keys($element), 0, 12))->toBe(P4A_LEGACY_FIELDS);
        p4aAssertMobileParses($element);
        expect($element['two_minute_version'])->toBeString()->not->toBe('')
            ->and($element['shown_up'])->toBeBool()
            ->and($element['streak_current'])->toBeInt()
            ->and($element['streak_state'])->toBeIn(['ok', 'at_risk', 'restart']);
    }

    expect($data[0]['week_recorded_days'])->toBeNull()
        ->and($data[1])->toMatchArray(['unit' => 'páginas', 'target' => 8, 'accumulated_amount' => 1, 'completion_percent' => 0, 'completed' => false, 'objective_key' => 'SALUD'])
        ->and($data[2])->toMatchArray(['times_per_week' => 3, 'week_recorded_days' => 1])
        ->and($data[3]['week_recorded_days'])->toBeNull();
});

test('legacy semantics: target is server-computed, a no-entry day is flattened to 0/false', function () {
    $user = User::factory()->create();
    p4aHabit('2026-09-01', ['name' => 'q', 'daily_target' => 0], fn ($f) => $f->quantitative('min', 1), $user);

    $element = $this->getJson('/api/v1/habits/today', p4aBearer($user))->json('data.0');

    expect($element)->toMatchArray([
        'target' => 1, 'accumulated_amount' => 0, 'completion_percent' => 0, 'completed' => false, 'peak_amount' => 0, 'shown_up' => false,
    ]);
});

test('spec: a 2-minute-only day is shown_up but never completed and never changes the amount', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', [], fn ($f) => $f->quantitative('páginas', 30), $user);

    $element = $this->postJson("/api/v1/habits/{$habit->id}/two-minute", [], p4aBearer($user))->assertOk()->json('data');

    expect($element)->toMatchArray(['accumulated_amount' => 0, 'completion_percent' => 0, 'completed' => false, 'peak_amount' => 0, 'shown_up' => true]);
});

test('increment, decrement and two-minute responses are identical to the matching today element', function (string $endpoint) {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create(['key' => 'LEER']);
    $habit = p4aHabit('2026-09-01', ['objective_id' => $objective->id], fn ($f) => $f->quantitative('páginas', 3)->timesPerWeek(3), $user);
    p4aDays($habit, ['2026-09-21', '2026-09-22']);
    $headers = p4aBearer($user);

    if ($endpoint === 'decrement') {
        $this->postJson("/api/v1/habits/{$habit->id}/increment", [], $headers)->assertOk();
        $this->postJson("/api/v1/habits/{$habit->id}/increment", [], $headers)->assertOk();
    }

    $written = $this->postJson("/api/v1/habits/{$habit->id}/{$endpoint}", [], $headers)->assertOk()->json();
    $listed = $this->getJson('/api/v1/habits/today', $headers)->json('data.0');

    expect($written)->toBe(['data' => $listed]);
})->with(['increment', 'decrement', 'two-minute']);

test('spec: a second two-minute call the same day changes nothing', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', [], user: $user);
    $headers = p4aBearer($user);

    $first = $this->postJson("/api/v1/habits/{$habit->id}/two-minute", [], $headers)->assertOk()->json();
    $this->travel(3)->hours();
    $second = $this->postJson("/api/v1/habits/{$habit->id}/two-minute", [], $headers)->assertOk()->json();

    expect($second)->toBe($first)
        ->and($habit->days()->count())->toBe(1)
        ->and($habit->entries()->count())->toBe(0);
});

test('two-minute after completing the day keeps completed and does not double-count the streak', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-20', [], user: $user);
    p4aDays($habit, p4aRange('2026-09-20', '2026-09-26'));
    $headers = p4aBearer($user);

    $this->postJson("/api/v1/habits/{$habit->id}/increment", [], $headers)->assertOk()->assertJsonPath('data.streak_current', 8);
    $this->postJson("/api/v1/habits/{$habit->id}/two-minute", [], $headers)->assertOk()
        ->assertJsonPath('data.completed', true)
        ->assertJsonPath('data.streak_current', 8);
});

test('two-minute shares the non-disclosing 404 set: unknown, another user\'s and archived habits', function () {
    $user = User::factory()->create();
    $foreign = p4aHabit('2026-09-01');
    $archived = p4aHabit('2026-09-01', ['archived_at' => now()], user: $user);
    $headers = p4aBearer($user);

    $bodies = [];

    foreach ([999999, $foreign->id, $archived->id] as $id) {
        $response = $this->postJson("/api/v1/habits/{$id}/two-minute", [], $headers)->assertNotFound();
        $bodies[] = $response->json();
    }

    expect($bodies[1])->toBe($bodies[0])
        ->and($bodies[2])->toBe($bodies[0])
        ->and(DB::table('habit_days')->count())->toBe(0);
});

test('two-minute requires a mobile-ability token, like increment', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', [], user: $user);
    $mcp = p4aBearer($user, 'mcp', ['mcp']);

    $incrementStatus = $this->postJson("/api/v1/habits/{$habit->id}/increment", [], $mcp)->status();
    app('auth')->forgetGuards();
    $twoMinuteStatus = $this->postJson("/api/v1/habits/{$habit->id}/two-minute", [], $mcp)->status();
    app('auth')->forgetGuards();
    $anonymous = $this->postJson("/api/v1/habits/{$habit->id}/two-minute")->status();

    expect($twoMinuteStatus)->toBe($incrementStatus)
        ->and($twoMinuteStatus)->toBeIn([401, 403])
        ->and($anonymous)->toBe(401)
        ->and($habit->days()->count())->toBe(0);
});

test('a single-miss habit reports streak_current 10 and at_risk through the API (spec scenario)', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', [], user: $user);
    p4aDays($habit, p4aRange('2026-09-16', '2026-09-25'));

    $this->getJson('/api/v1/habits/today', p4aBearer($user))->assertOk()
        ->assertJsonPath('data.0.streak_current', 10)
        ->assertJsonPath('data.0.streak_state', 'at_risk');
});

test('the today query count does not grow with habits or their history', function () {
    $user = User::factory()->create();
    $headers = p4aBearer($user);
    $first = p4aHabit('2026-09-01', [], user: $user);
    p4aDays($first, p4aRange('2026-09-01', '2026-09-26'));

    $one = mosQueryCount(fn () => $this->getJson('/api/v1/habits/today', $headers)->assertOk());

    foreach (range(1, 6) as $i) {
        $h = p4aHabit('2026-09-01', ['objective_id' => Objective::factory()->for($user)->create()->id], user: $user);
        p4aDays($h, p4aRange('2026-09-10', '2026-09-26'));
    }

    $many = mosQueryCount(fn () => $this->getJson('/api/v1/habits/today', $headers)->assertOk());

    expect($many)->toBe($one);
});

test('week_recorded_days never counts a day where nothing was recorded (undone 2-minute)', function () {
    $user = User::factory()->create();
    $habit = p4aHabit('2026-09-01', [], fn ($f) => $f->timesPerWeek(3), $user);
    p4aDay($habit, '2026-09-22');

    $this->actingAs($user)->post("/habits/{$habit->id}/two-minute")->assertRedirect();
    $this->actingAs($user)->delete("/habits/{$habit->id}/two-minute")->assertRedirect();
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/habits/today', p4aBearer($user))->assertOk()
        ->assertJsonPath('data.0.week_recorded_days', 1)
        ->assertJsonPath('data.0.shown_up', false);
});

test('OpenAPI documents two-minute and the 17-field TodayHabit with the legacy 12 unchanged', function () {
    $spec = json_decode((string) file_get_contents(base_path('openapi/v1.json')), true);
    $schema = $spec['components']['schemas']['TodayHabit'];

    expect($spec['paths'])->toHaveKey('/api/v1/habits/{habit}/two-minute')
        ->and($spec['paths']['/api/v1/habits/{habit}/two-minute'])->toHaveKey('post')
        ->and(array_keys($schema['properties']))->toEqualCanonicalizing([...P4A_LEGACY_FIELDS, ...P4A_NEW_FIELDS])
        ->and($schema['required'])->toEqualCanonicalizing([...P4A_LEGACY_FIELDS, ...P4A_NEW_FIELDS])
        ->and($schema['properties']['streak_state']['enum'])->toEqualCanonicalizing(['ok', 'at_risk', 'restart'])
        ->and($schema['properties']['week_recorded_days']['nullable'] ?? false)->toBeTrue()
        ->and($schema['properties']['unit']['nullable'] ?? false)->toBeTrue()
        ->and($schema['properties']['times_per_week']['nullable'] ?? false)->toBeTrue();
});
