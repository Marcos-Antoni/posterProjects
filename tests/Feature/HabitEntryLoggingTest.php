<?php

use App\Models\Habit;
use App\Models\HabitDay;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('guests are redirected to login when logging an entry', function () {
    $habit = Habit::factory()->create();

    $response = $this->post("/habits/{$habit->id}/entries", ['amount' => 5]);

    $response->assertRedirect('/login');
});

test('a user cannot log entries against another user\'s habit', function () {
    $stranger = User::factory()->create();
    $habit = Habit::factory()->quantitative('pages', 20)->create();

    $response = $this->actingAs($stranger)->post("/habits/{$habit->id}/entries", ['amount' => 5]);

    $response->assertForbidden();
    expect($habit->entries()->count())->toBe(0);
});

test('partial entries accumulate into the day and the real percent is persisted', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create();

    $this->actingAs($user)->post("/habits/{$habit->id}/entries", ['amount' => 5])->assertRedirect();

    $day = $habit->days()->firstOrFail();
    expect($day->accumulated_amount)->toBe(5)
        ->and($day->completion_percent)->toBe(25)
        ->and($day->completed)->toBeFalse();

    $this->actingAs($user)->post("/habits/{$habit->id}/entries", ['amount' => 10])->assertRedirect();

    $day->refresh();
    expect($day->accumulated_amount)->toBe(15)
        ->and($day->completion_percent)->toBe(75)
        ->and($day->completed)->toBeFalse();

    $this->actingAs($user)->post("/habits/{$habit->id}/entries", ['amount' => 5])->assertRedirect();

    $day->refresh();
    expect($day->accumulated_amount)->toBe(20)
        ->and($day->completion_percent)->toBe(100)
        ->and($day->completed)->toBeTrue()
        ->and($habit->days()->count())->toBe(1)
        ->and($habit->entries()->count())->toBe(3);
});

test('a string amount is cast, matching what real form submissions send', function () {
    // Browser submissions (FormData) always deliver the amount as a
    // string; regression for the guard that used to log 1 in that case.
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create();

    $this->actingAs($user)->post("/habits/{$habit->id}/entries", ['amount' => '15'])->assertRedirect();

    $day = $habit->days()->firstOrFail();
    expect($day->accumulated_amount)->toBe(15)
        ->and($day->completion_percent)->toBe(75);
});

test('the day is completed exactly at the target and the percent can exceed 100', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create();

    $this->actingAs($user)->post("/habits/{$habit->id}/entries", ['amount' => 20]);

    $day = $habit->days()->firstOrFail();
    expect($day->completion_percent)->toBe(100)
        ->and($day->completed)->toBeTrue();

    $this->actingAs($user)->post("/habits/{$habit->id}/entries", ['amount' => 4]);

    $day->refresh();
    expect($day->accumulated_amount)->toBe(24)
        ->and($day->completion_percent)->toBe(120)
        ->and($day->completed)->toBeTrue();
});

test('a yes/no habit is completed with a single check-in and takes no amount', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->yesNo()->create();

    $this->actingAs($user)->post("/habits/{$habit->id}/entries")->assertRedirect();

    $day = $habit->days()->firstOrFail();
    expect($day->accumulated_amount)->toBe(1)
        ->and($day->completion_percent)->toBe(100)
        ->and($day->completed)->toBeTrue()
        ->and($habit->entries()->firstOrFail()->amount)->toBe(1);
});

test('a quantitative habit requires a positive integer amount', function (array $payload) {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create();

    $response = $this->actingAs($user)->post("/habits/{$habit->id}/entries", $payload);

    $response->assertSessionHasErrors('amount');
    expect($habit->entries()->count())->toBe(0);
})->with([
    'missing amount' => [[]],
    'zero amount' => [['amount' => 0]],
    'non-integer amount' => [['amount' => 'many']],
]);

test('an archived habit rejects new entries', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->archived()->create();

    $response = $this->actingAs($user)->post("/habits/{$habit->id}/entries");

    $response->assertSessionHasErrors('habit');
    expect($habit->entries()->count())->toBe(0);
});

test('increments are read from the locked row, not from stale in-memory models', function () {
    $user = User::factory()->create();
    Habit::factory()->for($user)->quantitative('pages', 20)->create();

    // Two independent model instances simulate two concurrent requests
    // that each loaded the habit before the other one wrote: the day
    // aggregate must come from the row locked inside the transaction,
    // never from whatever the instance had in memory.
    $first = Habit::query()->firstOrFail();
    $second = Habit::query()->firstOrFail();

    $first->recordEntry(5);
    $second->recordEntry(10);

    $day = HabitDay::query()->firstOrFail();
    expect($day->accumulated_amount)->toBe(15)
        ->and($day->completion_percent)->toBe(75);
});

test('entries are stamped with the current utc timestamp automatically', function () {
    $this->travelTo(Carbon::parse('2026-07-10 14:30:00', 'UTC'));

    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->yesNo()->create();

    $this->actingAs($user)->post("/habits/{$habit->id}/entries");

    $entry = $habit->entries()->firstOrFail();
    expect($entry->logged_at->clone()->setTimezone('UTC')->toDateTimeString())->toBe('2026-07-10 14:30:00');
});

test('an entry at 23:30 utc-6 lands on the utc-6 day even though it is already the next utc day', function () {
    // 2026-07-11 05:30 UTC == 2026-07-10 23:30 in UTC-6.
    $this->travelTo(Carbon::parse('2026-07-11 05:30:00', 'UTC'));

    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create();

    $this->actingAs($user)->post("/habits/{$habit->id}/entries", ['amount' => 5]);

    $day = $habit->days()->firstOrFail();
    expect($day->entry_date->toDateString())->toBe('2026-07-10');
});

test('entries on both utc sides of the same utc-6 day accumulate into one habit day', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create();

    // 2026-07-10 06:30 UTC == 2026-07-10 00:30 UTC-6 (start of the day).
    $this->travelTo(Carbon::parse('2026-07-10 06:30:00', 'UTC'));
    $this->actingAs($user)->post("/habits/{$habit->id}/entries", ['amount' => 5]);

    // 2026-07-11 05:30 UTC == 2026-07-10 23:30 UTC-6 (end of the same day).
    $this->travelTo(Carbon::parse('2026-07-11 05:30:00', 'UTC'));
    $this->actingAs($user)->post("/habits/{$habit->id}/entries", ['amount' => 10]);

    expect($habit->days()->count())->toBe(1);

    $day = $habit->days()->firstOrFail();
    expect($day->entry_date->toDateString())->toBe('2026-07-10')
        ->and($day->accumulated_amount)->toBe(15);
});

test('the first entry of the day records the planned-vs-actual delta in utc-6 minutes', function () {
    // Planned 07:00 UTC-6; first entry at 13:25 UTC == 07:25 UTC-6 → +25.
    $this->travelTo(Carbon::parse('2026-07-10 13:25:00', 'UTC'));

    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->yesNo()->plannedAt('07:00:00')->create();

    $this->actingAs($user)->post("/habits/{$habit->id}/entries");

    $day = $habit->days()->firstOrFail();
    expect($day->planned_delta_minutes)->toBe(25);

    // A later entry the same day must not overwrite the first delta.
    $this->travelTo(Carbon::parse('2026-07-10 20:00:00', 'UTC'));
    $this->actingAs($user)->post("/habits/{$habit->id}/entries");

    expect($day->refresh()->planned_delta_minutes)->toBe(25);
});

test('an entry earlier than the planned time records a negative delta', function () {
    // Planned 07:00 UTC-6; entry at 12:45 UTC == 06:45 UTC-6 → -15.
    $this->travelTo(Carbon::parse('2026-07-10 12:45:00', 'UTC'));

    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->yesNo()->plannedAt('07:00:00')->create();

    $this->actingAs($user)->post("/habits/{$habit->id}/entries");

    expect($habit->days()->firstOrFail()->planned_delta_minutes)->toBe(-15);
});

test('habits without a planned time keep a null delta', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->yesNo()->create();

    $this->actingAs($user)->post("/habits/{$habit->id}/entries");

    expect($habit->days()->firstOrFail()->planned_delta_minutes)->toBeNull();
});

test('a habit stays completed after a decrement corrects an over-tap, even after a further increment', function () {
    // The un-completion bug (design D-1): with the old non-monotone
    // `completed = accumulated >= target`, three decrements on a habit
    // completed at accumulated 5/target 5 leave accumulated 2, and the
    // NEXT increment re-evaluates `2 >= 5` -> false, un-completing a day
    // FROM AN INCREMENT. This pins the monotone fix with a minimal
    // yes/no repro: +1, -1, +1 must never un-complete.
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->yesNo()->create();

    $habit->recordEntry(1);
    $habit->decrementToday();
    $habit->recordEntry(1);

    $day = $habit->days()->firstOrFail();
    expect($day->completed)->toBeTrue()
        ->and($day->accumulated_amount)->toBe(1);
});

test('decrementing a habit day at zero throws and leaves the row byte-unchanged', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 5)->create();
    HabitDay::factory()->for($habit)->create([
        'entry_date' => Habit::todayLocalDate()->toDateString(),
        'accumulated_amount' => 0,
        'peak_amount' => 0,
        'completion_percent' => 0,
        'completed' => false,
    ]);

    $before = (array) DB::table('habit_days')->where('habit_id', $habit->id)->sole();

    expect(fn () => $habit->decrementToday())->toThrow(ValidationException::class);

    $after = (array) DB::table('habit_days')->where('habit_id', $habit->id)->sole();
    expect($after)->toBe($before);
});

test('accumulated never exceeds peak, peak never exceeds the entry ledger, and they agree only on decrement-free days', function () {
    // Counter-example from design D-3: proposal Decision 4 claimed
    // peak_amount always equals the day's entry sum. +1, -1, +1 leaves
    // peak=1 while the ledger holds 2 — equality only holds before any
    // decrement touches the day.
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create();
    $ledgerSum = fn (): int => (int) $habit->entries()->sum('amount');

    $habit->recordEntry(1);
    $day = $habit->days()->firstOrFail();
    expect($day->accumulated_amount)->toBeLessThanOrEqual($day->peak_amount)
        ->and($day->peak_amount)->toBeLessThanOrEqual($ledgerSum())
        ->and($day->peak_amount)->toBe($ledgerSum());

    $habit->decrementToday();
    $day->refresh();
    expect($day->accumulated_amount)->toBe(0)
        ->and($day->peak_amount)->toBe(1)
        ->and($day->accumulated_amount)->toBeLessThanOrEqual($day->peak_amount)
        ->and($day->peak_amount)->toBeLessThanOrEqual($ledgerSum());

    $habit->recordEntry(1);
    $day->refresh();
    expect($day->accumulated_amount)->toBe(1)
        ->and($day->peak_amount)->toBe(1)
        ->and($ledgerSum())->toBe(2)
        ->and($day->accumulated_amount)->toBeLessThanOrEqual($day->peak_amount)
        ->and($day->peak_amount)->toBeLessThanOrEqual($ledgerSum())
        ->and($day->peak_amount)->not->toBe($ledgerSum());
});

test('two decrements read from the locked row leave zero, never a negative amount', function () {
    // Mirrors the increment concurrency test above (:123-140): two
    // independent model instances simulate two requests that each
    // loaded the habit before the other wrote. The second decrement
    // must re-read the row under the lock and reject, not trust a
    // stale in-memory accumulated_amount of 1.
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->create();
    $habit->recordEntry(1);

    $first = Habit::query()->firstOrFail();
    $second = Habit::query()->firstOrFail();

    $first->decrementToday();

    expect(fn () => $second->decrementToday())->toThrow(ValidationException::class);

    $day = HabitDay::query()->firstOrFail();
    expect($day->accumulated_amount)->toBe(0);
});

test('a decremented-to-zero completed day still counts toward the current streak', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->yesNo()->create();

    $habit->recordEntry(1);
    $habit->decrementToday();

    $day = $habit->days()->firstOrFail();
    expect($day->completed)->toBeTrue()
        ->and($day->accumulated_amount)->toBe(0)
        ->and($habit->currentStreak())->toBe(1);
});
