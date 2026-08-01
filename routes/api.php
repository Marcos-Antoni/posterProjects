<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BoardColumnController;
use App\Http\Controllers\Api\V1\HabitController;
use App\Http\Controllers\Api\V1\HabitEntryController;
use App\Http\Controllers\Api\V1\IssueController;
use App\Http\Controllers\Api\V1\LabelController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\QrLoginController;
use App\Http\Controllers\Api\V1\SprintController;
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
    Route::get('projects', [ProjectController::class, 'index'])->name('api.v1.projects.index');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('api.v1.projects.show');
    Route::get('projects/{project}/issues', [IssueController::class, 'index'])->name('api.v1.projects.issues.index');
    Route::get('projects/{project}/issues/{issue}', [IssueController::class, 'show'])->name('api.v1.projects.issues.show');
    Route::get('projects/{project}/board-columns', [BoardColumnController::class, 'index'])->name('api.v1.projects.board-columns.index');
    Route::get('projects/{project}/sprints', [SprintController::class, 'index'])->name('api.v1.projects.sprints.index');
    Route::get('projects/{project}/labels', [LabelController::class, 'index'])->name('api.v1.projects.labels.index');

    // `today` MUST be registered before any `habits/{habit}` route so the
    // literal segment never gets swallowed by the numeric habit-id pattern.
    Route::get('habits/today', [HabitController::class, 'today'])->name('api.v1.habits.today');
    Route::post('habits/{habit}/increment', [HabitEntryController::class, 'increment'])
        ->whereNumber('habit')
        ->name('api.v1.habits.increment');
    Route::post('habits/{habit}/decrement', [HabitEntryController::class, 'decrement'])
        ->whereNumber('habit')
        ->name('api.v1.habits.decrement');
});
