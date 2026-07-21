<?php

use App\Http\Controllers\ProjectController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    return $request->user()
        ? redirect()->route('projects.index')
        : redirect()->route('login');
})->name('home');

Route::middleware('auth')->group(function (): void {
    Route::resource('projects', ProjectController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('projects/trash', [ProjectController::class, 'trash'])->name('projects.trash');
    Route::post('projects/{project}/restore', [ProjectController::class, 'restore'])->name('projects.restore')->withTrashed();
    Route::delete('projects/{project}/force', [ProjectController::class, 'forceDelete'])->name('projects.forceDelete')->withTrashed();
});

require __DIR__.'/auth.php';
