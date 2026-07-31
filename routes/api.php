<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProjectController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])
    ->middleware('throttle:api-login')
    ->name('api.v1.login');

Route::middleware(['auth:sanctum', 'abilities:mobile'])->group(function (): void {
    Route::get('user', [AuthController::class, 'user'])->name('api.v1.user');
    Route::post('logout', [AuthController::class, 'logout'])->name('api.v1.logout');
    Route::get('projects', [ProjectController::class, 'index'])->name('api.v1.projects.index');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('api.v1.projects.show');
});
