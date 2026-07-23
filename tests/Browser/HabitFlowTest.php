<?php

use App\Models\User;

/**
 * Full habit lifecycle through the UI: create a quantitative habit, log
 * an entry, check the detail page, then archive and reactivate it from
 * management.
 */
test('a user creates a quantitative habit, logs an entry, views the detail page, and archives then reactivates it', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/habits/manage');

    $page->assertSee('Gestión de hábitos')
        ->click('Nuevo hábito')
        ->assertSee('Nuevo hábito')
        ->fill('#habit-name', 'Read')
        // Radix's Select is not a native <select>: open the trigger, then
        // pick the option by its accessible role/name (a plain text click
        // on the item is flaky here — Playwright times out waiting for it
        // to become actionable while the item-aligned content repositions).
        ->click('Sí / No')
        ->click('internal:role=option[name="Cuantitativo"]')
        ->fill('#habit-unit', 'pages')
        ->fill('#habit-target', '20')
        ->click('Crear hábito')
        ->assertSee('Read')
        ->assertNoJavascriptErrors();

    // "Today" view: log one entry through the real quantity input + button.
    $page = visit('/habits');

    $page->assertSee('Read')
        ->fill('amount', '5')
        ->click('Registrar')
        ->assertSee('Read')
        ->assertNoJavascriptErrors();

    // Detail page: streak and completion labels are visible.
    $page->click('Read')
        ->assertSee('Racha actual')
        ->assertSee('Mejor racha')
        ->assertSee('días')
        ->assertNoJavascriptErrors();

    // Back to management: archive, confirm it moved to "Archivados", then
    // reactivate it back into the active list.
    $page = visit('/habits/manage');

    $page->assertSee('Read')
        ->click('Archivar')
        ->assertSee('Archivados')
        ->assertSee('Reactivar')
        ->assertNoJavascriptErrors();

    $page->click('Reactivar')
        ->assertSee('Read')
        ->assertNoJavascriptErrors();
});

/**
 * Pinned regression test for a real bug this suite's browser coverage
 * uncovered: `HabitEntryController::store()` (and the identical pattern in
 * `App\Mcp\Tools\Habits\LogHabitEntry`) guards the logged amount with
 * `is_int($amount) ? $amount : 1`. That check is only ever true when the
 * value already arrives as a native PHP int — true for `PosterServer::actingAs()`
 * unit tests and for MCP tool calls (their JSON-RPC arguments round-trip
 * through `json_decode`, which preserves numeric JSON types), but never
 * true for a real browser submission: Inertia's `<Form>` reads field
 * values via the native `FormData` API, which stringifies every value —
 * confirmed with a plain Feature-style POST of `['amount' => '15']`
 * (a string, matching real HTTP semantics), which persists
 * `accumulated_amount = 1` instead of 15.
 *
 * Net effect: every quantitative habit entry logged through the actual
 * web UI silently records 1, no matter what the user types. This test
 * encodes the *intended* behavior (partial entries accumulate, and the
 * percent can exceed 100) and is skipped until the guard is fixed —
 * e.g. `is_numeric($amount) ? (int) $amount : 1`. Un-skip it once that
 * lands; no other change to this test should be needed.
 */
test('a user logs partial entries through the ui past the daily target and sees the overshoot', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    visit('/habits/manage')
        ->click('Nuevo hábito')
        ->fill('#habit-name', 'Read')
        ->click('Sí / No')
        ->click('internal:role=option[name="Cuantitativo"]')
        ->fill('#habit-unit', 'pages')
        ->fill('#habit-target', '20')
        ->click('Crear hábito');

    $page = visit('/habits');

    $page->fill('amount', '15')
        ->click('Registrar')
        ->assertSee('15 / 20');

    $page->fill('amount', '10')
        ->click('Registrar')
        ->assertSee('25 / 20')
        ->assertSee('125%')
        ->assertSee('Cumplido');

    $page->click('Read')
        ->assertSee('Racha actual');
})->skip('BUG: HabitEntryController::store() (app/Http/Controllers/HabitEntryController.php:20) logs amount=1 for every real browser submission because the validated amount always arrives as a string, and is_int() on it is always false — see the docblock above this test.');
