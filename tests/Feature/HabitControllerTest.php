<?php

use App\Enums\HabitType;
use App\Enums\RecurrenceType;
use App\Models\Habit;
use App\Models\User;

test('guests are redirected to login when visiting the habits management page', function () {
    $response = $this->get('/habits/manage');

    $response->assertRedirect('/login');
});

test('a user only sees their own habits on the management page', function () {
    $user = User::factory()->create();
    $own = Habit::factory()->for($user)->create(['name' => 'Mine']);
    Habit::factory()->create(['name' => 'Not mine']);

    $response = $this->actingAs($user)->get('/habits/manage', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'habits/index');

    $habits = $response->json('props.habits');

    expect($habits)->toHaveCount(1)
        ->and($habits[0]['id'])->toBe($own->id);
});

test('a habit can be created for every type and recurrence combination', function (array $payload, array $expected) {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/habits', $payload);

    $response->assertRedirect('/habits/manage');

    $habit = Habit::query()->where('user_id', $user->id)->firstOrFail();

    foreach ($expected as $attribute => $value) {
        expect($habit->getAttribute($attribute))->toEqual($value);
    }
})->with([
    'yes/no daily' => [
        ['name' => 'Meditate', 'habit_type' => 'yes_no', 'recurrence_type' => 'daily'],
        ['habit_type' => HabitType::YesNo, 'recurrence_type' => RecurrenceType::Daily, 'unit' => null, 'daily_target' => null],
    ],
    'yes/no specific weekdays' => [
        ['name' => 'Gym', 'habit_type' => 'yes_no', 'recurrence_type' => 'specific_weekdays', 'weekdays' => [1, 3, 5]],
        ['recurrence_type' => RecurrenceType::SpecificWeekdays, 'weekdays' => [1, 3, 5], 'times_per_week' => null],
    ],
    'yes/no times per week' => [
        ['name' => 'Run', 'habit_type' => 'yes_no', 'recurrence_type' => 'times_per_week', 'times_per_week' => 3],
        ['recurrence_type' => RecurrenceType::TimesPerWeek, 'times_per_week' => 3, 'weekdays' => null],
    ],
    'quantitative daily' => [
        ['name' => 'Read', 'habit_type' => 'quantitative', 'unit' => 'pages', 'daily_target' => 20, 'recurrence_type' => 'daily'],
        ['habit_type' => HabitType::Quantitative, 'unit' => 'pages', 'daily_target' => 20],
    ],
    'quantitative specific weekdays' => [
        ['name' => 'Swim', 'habit_type' => 'quantitative', 'unit' => 'laps', 'daily_target' => 10, 'recurrence_type' => 'specific_weekdays', 'weekdays' => [2, 4]],
        ['unit' => 'laps', 'daily_target' => 10, 'weekdays' => [2, 4]],
    ],
    'quantitative times per week' => [
        ['name' => 'Write', 'habit_type' => 'quantitative', 'unit' => 'words', 'daily_target' => 500, 'recurrence_type' => 'times_per_week', 'times_per_week' => 4],
        ['unit' => 'words', 'daily_target' => 500, 'times_per_week' => 4],
    ],
]);

test('fields that do not apply to the chosen type or recurrence are dropped', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/habits', [
        'name' => 'Meditate',
        'habit_type' => 'yes_no',
        'unit' => 'pages',
        'daily_target' => 20,
        'recurrence_type' => 'daily',
        'weekdays' => [1, 2],
        'times_per_week' => 3,
    ]);

    $response->assertRedirect('/habits/manage');

    $habit = Habit::query()->where('user_id', $user->id)->firstOrFail();

    expect($habit->unit)->toBeNull()
        ->and($habit->daily_target)->toBeNull()
        ->and($habit->weekdays)->toBeNull()
        ->and($habit->times_per_week)->toBeNull();
});

test('a habit can be created with an optional planned time', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/habits', [
        'name' => 'Meditate',
        'habit_type' => 'yes_no',
        'recurrence_type' => 'daily',
        'planned_time' => '07:30',
    ]);

    $response->assertRedirect('/habits/manage');

    $habit = Habit::query()->where('user_id', $user->id)->firstOrFail();

    expect($habit->planned_time)->toBe('07:30:00');
});

test('invalid payloads are rejected with a validation error', function (array $payload, string $field) {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/habits', $payload);

    $response->assertSessionHasErrors($field);
    expect(Habit::query()->count())->toBe(0);
})->with([
    'missing name' => [
        ['habit_type' => 'yes_no', 'recurrence_type' => 'daily'],
        'name',
    ],
    'invalid habit type' => [
        ['name' => 'X', 'habit_type' => 'sometimes', 'recurrence_type' => 'daily'],
        'habit_type',
    ],
    'invalid recurrence type' => [
        ['name' => 'X', 'habit_type' => 'yes_no', 'recurrence_type' => 'monthly'],
        'recurrence_type',
    ],
    'quantitative without unit' => [
        ['name' => 'X', 'habit_type' => 'quantitative', 'daily_target' => 20, 'recurrence_type' => 'daily'],
        'unit',
    ],
    'quantitative without daily target' => [
        ['name' => 'X', 'habit_type' => 'quantitative', 'unit' => 'pages', 'recurrence_type' => 'daily'],
        'daily_target',
    ],
    'quantitative with zero daily target' => [
        ['name' => 'X', 'habit_type' => 'quantitative', 'unit' => 'pages', 'daily_target' => 0, 'recurrence_type' => 'daily'],
        'daily_target',
    ],
    'specific weekdays without weekdays' => [
        ['name' => 'X', 'habit_type' => 'yes_no', 'recurrence_type' => 'specific_weekdays'],
        'weekdays',
    ],
    'specific weekdays with empty array' => [
        ['name' => 'X', 'habit_type' => 'yes_no', 'recurrence_type' => 'specific_weekdays', 'weekdays' => []],
        'weekdays',
    ],
    'weekday below monday' => [
        ['name' => 'X', 'habit_type' => 'yes_no', 'recurrence_type' => 'specific_weekdays', 'weekdays' => [0]],
        'weekdays.0',
    ],
    'weekday above sunday' => [
        ['name' => 'X', 'habit_type' => 'yes_no', 'recurrence_type' => 'specific_weekdays', 'weekdays' => [8]],
        'weekdays.0',
    ],
    'duplicated weekdays' => [
        ['name' => 'X', 'habit_type' => 'yes_no', 'recurrence_type' => 'specific_weekdays', 'weekdays' => [1, 1]],
        'weekdays.0',
    ],
    'times per week missing' => [
        ['name' => 'X', 'habit_type' => 'yes_no', 'recurrence_type' => 'times_per_week'],
        'times_per_week',
    ],
    'times per week below one' => [
        ['name' => 'X', 'habit_type' => 'yes_no', 'recurrence_type' => 'times_per_week', 'times_per_week' => 0],
        'times_per_week',
    ],
    'times per week above seven' => [
        ['name' => 'X', 'habit_type' => 'yes_no', 'recurrence_type' => 'times_per_week', 'times_per_week' => 8],
        'times_per_week',
    ],
    'malformed planned time' => [
        ['name' => 'X', 'habit_type' => 'yes_no', 'recurrence_type' => 'daily', 'planned_time' => 'late'],
        'planned_time',
    ],
]);

test('the owner can update a habit and switching type clears stale fields', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create(['name' => 'Read']);

    $response = $this->actingAs($user)->patch("/habits/{$habit->id}", [
        'name' => 'Meditate',
        'habit_type' => 'yes_no',
        'recurrence_type' => 'times_per_week',
        'times_per_week' => 5,
    ]);

    $response->assertRedirect('/habits/manage');

    $habit->refresh();

    expect($habit->name)->toBe('Meditate')
        ->and($habit->habit_type)->toBe(HabitType::YesNo)
        ->and($habit->unit)->toBeNull()
        ->and($habit->daily_target)->toBeNull()
        ->and($habit->recurrence_type)->toBe(RecurrenceType::TimesPerWeek)
        ->and($habit->times_per_week)->toBe(5);
});

test('a user cannot update another user\'s habit', function () {
    $stranger = User::factory()->create();
    $habit = Habit::factory()->create(['name' => 'Private']);

    $response = $this->actingAs($stranger)->patch("/habits/{$habit->id}", [
        'name' => 'Hijacked',
        'habit_type' => 'yes_no',
        'recurrence_type' => 'daily',
    ]);

    $response->assertForbidden();
    expect($habit->refresh()->name)->toBe('Private');
});

test('the owner can archive and reactivate a habit', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->create();

    $this->actingAs($user)->post("/habits/{$habit->id}/archive")->assertRedirect('/habits/manage');

    expect($habit->refresh()->archived_at)->not->toBeNull();

    $this->actingAs($user)->post("/habits/{$habit->id}/unarchive")->assertRedirect('/habits/manage');

    expect($habit->refresh()->archived_at)->toBeNull();
});

test('a user cannot archive or unarchive another user\'s habit', function () {
    $stranger = User::factory()->create();
    $habit = Habit::factory()->create();

    $this->actingAs($stranger)->post("/habits/{$habit->id}/archive")->assertForbidden();

    expect($habit->refresh()->archived_at)->toBeNull();

    $archived = Habit::factory()->archived()->create();

    $this->actingAs($stranger)->post("/habits/{$archived->id}/unarchive")->assertForbidden();

    expect($archived->refresh()->archived_at)->not->toBeNull();
});

test('there is no destroy route for habits', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->create();

    $response = $this->actingAs($user)->delete("/habits/{$habit->id}");

    $response->assertMethodNotAllowed();
    $this->assertModelExists($habit);
});
