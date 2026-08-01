<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BoardColumnController;
use App\Http\Controllers\Api\V1\IssueController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\SprintController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])
    ->middleware('throttle:api-login')
    ->name('api.v1.login');

Route::middleware(['auth:sanctum', 'abilities:mobile'])->group(function (): void {
    Route::get('user', [AuthController::class, 'user'])->name('api.v1.user');
    Route::post('logout', [AuthController::class, 'logout'])->name('api.v1.logout');
    Route::get('projects', [ProjectController::class, 'index'])->name('api.v1.projects.index');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('api.v1.projects.show');
    Route::get('projects/{project}/issues', [IssueController::class, 'index'])->name('api.v1.projects.issues.index');
    Route::get('projects/{project}/issues/{issue}', [IssueController::class, 'show'])->name('api.v1.projects.issues.show');
    Route::get('projects/{project}/board-columns', [BoardColumnController::class, 'index'])->name('api.v1.projects.board-columns.index');
    Route::get('projects/{project}/sprints', [SprintController::class, 'index'])->name('api.v1.projects.sprints.index');
});
