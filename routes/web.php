<?php

use App\Http\Controllers\ControlMapEntryController;
use App\Http\Controllers\HabitController;
use App\Http\Controllers\HabitEntryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ItemDependencyController;
use App\Http\Controllers\ItemFocusController;
use App\Http\Controllers\NowController;
use App\Http\Controllers\ObjectiveController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\McpTokenController;
use App\Http\Controllers\Settings\MobileTokenController;
use App\Http\Controllers\Settings\MobileTokenQrController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    return $request->user()
        ? redirect()->route('now') // phase 3: Now is the landing (auth spec)
        : redirect()->route('login');
})->name('home');

Route::middleware('auth')->group(function (): void {
    // Settings — currently only the MCP token page. The token is a
    // single Sanctum PAT: regenerating kills the previous one, and the
    // plain text travels in a one-shot session flash (never a prop).
    Route::get('settings/mcp-token', [McpTokenController::class, 'show'])->name('settings.mcp-token.show');
    Route::post('settings/mcp-token', [McpTokenController::class, 'store'])->name('settings.mcp-token.store');

    // Appearance: the one theme selector (claro / oscuro / sistema), stored
    // per user. There is deliberately no profile or password route: the
    // password changes only via `php artisan marcos:set-password`.
    Route::get('settings/appearance', [AppearanceController::class, 'show'])->name('settings.appearance.show');
    Route::patch('settings/appearance', [AppearanceController::class, 'update'])->name('settings.appearance.update');

    // The mobile *token* is minted on the phone — via `POST /api/v1/login`
    // or by redeeming a QR *pass* (below) at `POST /api/v1/qr-login` — and
    // its plain text never reaches a browser. This sibling page shows
    // status, mints the short-lived QR pass, and revokes; see
    // MobileTokenController and design.md decision D-1 for why this is not
    // unified with settings/mcp-token.
    Route::get('settings/mobile-token', [MobileTokenController::class, 'show'])->name('settings.mobile-token.show');
    Route::delete('settings/mobile-token', [MobileTokenController::class, 'destroy'])->name('settings.mobile-token.destroy');

    // QR login pass: mints a short-lived, single-use credential the phone
    // redeems at POST /api/v1/qr-login. Plain JSON, never Inertia props —
    // see design.md "Interfaces / Contracts" for why the plaintext must
    // never ride on a prop (back-navigation would resurrect it).
    Route::post('settings/mobile-token/qr', [MobileTokenQrController::class, 'store'])
        ->middleware('throttle:qr-login-mint')
        ->name('settings.mobile-token.qr.store');
    Route::get('settings/mobile-token/qr/status', [MobileTokenQrController::class, 'status'])
        ->name('settings.mobile-token.qr.status');

    // Habits are personal to the authenticated user — never project
    // scoped. There is intentionally NO destroy route: habits can only
    // be archived (and reactivated), their history is never deleted.
    Route::get('habits', [HabitController::class, 'today'])->name('habits.today');
    Route::get('habits/manage', [HabitController::class, 'index'])->name('habits.index');
    Route::get('habits/{habit}', [HabitController::class, 'show'])->name('habits.show');
    Route::post('habits', [HabitController::class, 'store'])->name('habits.store');
    Route::patch('habits/{habit}', [HabitController::class, 'update'])->name('habits.update');
    Route::post('habits/{habit}/archive', [HabitController::class, 'archive'])->name('habits.archive');
    Route::post('habits/{habit}/unarchive', [HabitController::class, 'unarchive'])->name('habits.unarchive');
    Route::post('habits/{habit}/entries', [HabitEntryController::class, 'store'])->name('habits.entries.store');

    // --- phase 2: objectives, plans, items, dependencies ---
    // Marcos OS objectives (projects spec). `{objective}` is the KEY and is
    // resolved by the binder in AppServiceProvider among the owner's active
    // and closed objectives only: another owner's key, a draft and a retired
    // objective are all a plain 404. There is deliberately NO delete route for
    // objectives, plans or items: nothing is deleted, it is retired (Phase 6).
    Route::get('objectives', [ObjectiveController::class, 'index'])->name('objectives.index');
    Route::get('objectives/create', [ObjectiveController::class, 'create'])->name('objectives.create');
    Route::post('objectives', [ObjectiveController::class, 'store'])->name('objectives.store');
    Route::get('objectives/{objective}', [ObjectiveController::class, 'show'])->name('objectives.show');
    Route::get('objectives/{objective}/edit', [ObjectiveController::class, 'edit'])->name('objectives.edit');
    Route::patch('objectives/{objective}', [ObjectiveController::class, 'update'])->name('objectives.update');
    Route::post('objectives/{objective}/activate', [ObjectiveController::class, 'activate'])->name('objectives.activate');
    Route::post('objectives/{objective}/reopen', [ObjectiveController::class, 'reopen'])->name('objectives.reopen');

    // Control map of the objective ({entry} is looked up inside it).
    Route::post('objectives/{objective}/control-map', [ControlMapEntryController::class, 'store'])->name('objectives.control-map.store');
    Route::patch('objectives/{objective}/control-map/{entry}', [ControlMapEntryController::class, 'update'])->name('objectives.control-map.update');
    Route::delete('objectives/{objective}/control-map/{entry}', [ControlMapEntryController::class, 'destroy'])->name('objectives.control-map.destroy');
    Route::post('objectives/{objective}/control-map/{entry}/convert', [ControlMapEntryController::class, 'convert'])->name('objectives.control-map.convert');

    // Plans: `{plan}` is an id scoped to the objective (scopeBindings), and a
    // retired plan never resolves.
    Route::scopeBindings()->group(function (): void {
        Route::get('objectives/{objective}/plans/create', [PlanController::class, 'create'])->name('objectives.plans.create');
        Route::post('objectives/{objective}/plans', [PlanController::class, 'store'])->name('objectives.plans.store');
        Route::get('objectives/{objective}/plans/{plan}', [PlanController::class, 'show'])->name('objectives.plans.show');
        Route::get('objectives/{objective}/plans/{plan}/edit', [PlanController::class, 'edit'])->name('objectives.plans.edit');
        Route::patch('objectives/{objective}/plans/{plan}', [PlanController::class, 'update'])->name('objectives.plans.update');
        Route::post('objectives/{objective}/plans/{plan}/activate', [PlanController::class, 'activate'])->name('objectives.plans.activate');
        Route::post('objectives/{objective}/plans/{plan}/move', [PlanController::class, 'move'])->name('objectives.plans.move');
        Route::post('objectives/{objective}/plans/{plan}/items', [ItemController::class, 'store'])->name('objectives.plans.items.store');

        Route::post('objectives/{objective}/plans/{plan}/control-map', [ControlMapEntryController::class, 'storeForPlan'])->name('objectives.plans.control-map.store');
        Route::patch('objectives/{objective}/plans/{plan}/control-map/{entry}', [ControlMapEntryController::class, 'updateForPlan'])->name('objectives.plans.control-map.update');
        Route::delete('objectives/{objective}/plans/{plan}/control-map/{entry}', [ControlMapEntryController::class, 'destroyForPlan'])->name('objectives.plans.control-map.destroy');
    });

    // Items: `{item}` is the public KEY ("SALUD-7"), never route-model
    // bound. `Item::resolveByKey()` turns every malformed, mismatched,
    // unknown or retired key into the same 404 (issues spec deep links).
    Route::get('objectives/{objective}/items/{item}', [ItemController::class, 'show'])->name('objectives.items.show');
    Route::patch('objectives/{objective}/items/{item}', [ItemController::class, 'update'])->name('objectives.items.update');
    Route::post('objectives/{objective}/items/{item}/check', [ItemController::class, 'check'])->name('objectives.items.check');
    Route::post('objectives/{objective}/items/{item}/uncheck', [ItemController::class, 'uncheck'])->name('objectives.items.uncheck');
    Route::post('objectives/{objective}/items/{item}/move', [ItemController::class, 'move'])->name('objectives.items.move');

    // Dependencies ("completing A unlocks B"); the other end is a key from
    // any of the owner's objectives. Removing an edge is not a retirement.
    Route::post('objectives/{objective}/items/{item}/prerequisites', [ItemDependencyController::class, 'storePrerequisite'])->name('objectives.items.prerequisites.store');
    Route::delete('objectives/{objective}/items/{item}/prerequisites/{prerequisite}', [ItemDependencyController::class, 'destroyPrerequisite'])->name('objectives.items.prerequisites.destroy');
    Route::post('objectives/{objective}/items/{item}/unlocks', [ItemDependencyController::class, 'storeUnlock'])->name('objectives.items.unlocks.store');
    Route::delete('objectives/{objective}/items/{item}/unlocks/{dependent}', [ItemDependencyController::class, 'destroyUnlock'])->name('objectives.items.unlocks.destroy');
    // --- end phase 2 ---

    // --- phase 3: now and execution ---
    // "Ahora" (screen 2): the landing of every authenticated visit.
    Route::get('now', [NowController::class, 'show'])->name('now');
    Route::post('now/close-for-today', [NowController::class, 'closeForToday'])->name('now.close-for-today');

    // Execution on one item (now-focus): start it as THE active task, stop
    // it for today, and the "estoy trabado" fallback. `{item}` is the public
    // key, resolved with Item::resolveByKey() (404 when foreign or retired).
    Route::post('objectives/{objective}/items/{item}/start', [ItemFocusController::class, 'start'])->name('objectives.items.start');
    Route::post('objectives/{objective}/items/{item}/stop', [ItemFocusController::class, 'stop'])->name('objectives.items.stop');
    Route::post('objectives/{objective}/items/{item}/two-minute', [ItemFocusController::class, 'shrink'])->name('objectives.items.two-minute');
    // --- end phase 3 ---
});

require __DIR__.'/auth.php';
