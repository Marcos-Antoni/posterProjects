<?php

use App\Models\Habit;
use App\Models\User;
use Illuminate\Support\Carbon;

// Frozen "now": 2026-07-22 18:00 UTC == Wednesday 2026-07-22 12:00 UTC-6.
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-07-22 18:00:00', 'UTC'));
});

test('guests are redirected to login when visiting the today view', function () {
    $response = $this->get('/habits');

    $response->assertRedirect('/login');
});

test('the today view only lists active habits scheduled for the current utc-6 day', function () {
    $user = User::factory()->create();

    $daily = Habit::factory()->for($user)->daily()->create(['name' => 'Daily']);
    $scheduledToday = Habit::factory()->for($user)->specificWeekdays([3])->create(['name' => 'Wednesdays']);
    Habit::factory()->for($user)->specificWeekdays([2, 4])->create(['name' => 'Not today']);
    $weekly = Habit::factory()->for($user)->timesPerWeek(3)->create(['name' => 'Weekly quota']);
    Habit::factory()->for($user)->archived()->create(['name' => 'Archived']);
    Habit::factory()->daily()->create(['name' => 'Someone else\'s']);

    $response = $this->actingAs($user)->get('/habits', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'habits/today');
    $response->assertJsonPath('props.date', '2026-07-22');

    $habits = collect($response->json('props.habits'));

    expect($habits->pluck('id')->all())->toBe([$daily->id, $scheduledToday->id, $weekly->id]);
});

test('the today view exposes the day progress and the weekly quota count', function () {
    $user = User::factory()->create();
    $quantitative = Habit::factory()->for($user)->quantitative('pages', 20)->create(['name' => 'Read']);
    $weekly = Habit::factory()->for($user)->timesPerWeek(3)->create(['name' => 'Run']);

    $quantitative->recordEntry(15);

    // One record on Monday and one today → 2 recorded days this week.
    $this->travelTo(Carbon::parse('2026-07-20 18:00:00', 'UTC'));
    $weekly->recordEntry(1);
    $this->travelTo(Carbon::parse('2026-07-22 18:00:00', 'UTC'));
    $weekly->recordEntry(1);

    $response = $this->actingAs($user)->get('/habits', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();

    $habits = collect($response->json('props.habits'))->keyBy('name');

    expect($habits['Read']['today']['accumulated_amount'])->toBe(15)
        ->and($habits['Read']['today']['completion_percent'])->toBe(75)
        ->and($habits['Read']['today']['completed'])->toBeFalse()
        ->and($habits['Read']['week_recorded_days'])->toBeNull()
        ->and($habits['Run']['today']['completed'])->toBeTrue()
        ->and($habits['Run']['week_recorded_days'])->toBe(2);
});

test('habits without a record today expose a null progress', function () {
    $user = User::factory()->create();
    Habit::factory()->for($user)->daily()->create();

    $response = $this->actingAs($user)->get('/habits', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();

    expect($response->json('props.habits.0.today'))->toBeNull();
});
