<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HabitController;
use App\Http\Controllers\Api\V1\HabitEntryController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ObjectiveController;
use App\Http\Controllers\Api\V1\QrLoginController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])
    ->middleware('throttle:api-login')
    ->name('api.v1.login');

// Unauthenticated by design: a phone presents the plaintext QR pass to
// redeem a `mobile` token, mirroring `login` above. See design.md and the
// `api-auth` spec delta for why this is the second (and only other)
// exemption in ApiContractTest's bearerAuth check.
Route::post('qr-login', [QrLoginController::class, 'redeem'])
    ->middleware('throttle:api-qr-redeem')
    ->name('api.v1.qr-login');

Route::middleware(['auth:sanctum', 'abilities:mobile'])->group(function (): void {
    Route::get('user', [AuthController::class, 'user'])->name('api.v1.user');
    Route::post('logout', [AuthController::class, 'logout'])->name('api.v1.logout');

    // --- phase 2: objectives and items ---
    // Marcos OS (Phase 2 cutover): the projects, issues, board-columns,
    // sprints and labels routes are UNREGISTERED — a request to them gets the
    // generic 404 body. `{objective}` is the key, resolved by the shared
    // binder (AppServiceProvider); `{item}` is the public key, resolved in
    // code by `Item::resolveByKey()`.
    Route::get('objectives', [ObjectiveController::class, 'index'])->name('api.v1.objectives.index');
    Route::get('objectives/{objective}', [ObjectiveController::class, 'show'])->name('api.v1.objectives.show');
    Route::get('objectives/{objective}/items/{item}', [ItemController::class, 'show'])->name('api.v1.objectives.items.show');
    Route::post('objectives/{objective}/items/{item}/check', [ItemController::class, 'check'])->name('api.v1.objectives.items.check');
    // --- end phase 2 ---

    // `today` MUST be registered before any `habits/{habit}` route so the
    // literal segment never gets swallowed by the numeric habit-id pattern.
    Route::get('habits/today', [HabitController::class, 'today'])->name('api.v1.habits.today');
    Route::post('habits/{habit}/increment', [HabitEntryController::class, 'increment'])
        ->whereNumber('habit')
        ->name('api.v1.habits.increment');
    Route::post('habits/{habit}/decrement', [HabitEntryController::class, 'decrement'])
        ->whereNumber('habit')
        ->name('api.v1.habits.decrement');
    // --- phase 4: habits ---
    Route::post('habits/{habit}/two-minute', [HabitEntryController::class, 'twoMinute'])
        ->whereNumber('habit')
        ->name('api.v1.habits.two-minute');
    // --- end phase 4 ---
});
