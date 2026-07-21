<?php

use App\Http\Controllers\BoardColumnController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\IssueLabelController;
use App\Http\Controllers\IssueMoveController;
use App\Http\Controllers\LabelController;
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

    // Bound by key (not id) — the board and its nested routes are
    // addressed by the project's human-readable key, e.g. /projects/DEMO/board.
    Route::get('projects/{project:key}/board', [BoardController::class, 'show'])->name('projects.board');
    Route::post('projects/{project:key}/issues', [IssueController::class, 'store'])->name('projects.issues.store');

    // scopeBindings() ties {issue} to $project->issues() — an issue id
    // belonging to a different project resolves to a 404 instead of
    // silently operating on another project's data.
    Route::patch('projects/{project:key}/issues/{issue}/move', [IssueMoveController::class, 'move'])
        ->name('projects.issues.move')
        ->scopeBindings();

    // {issueKey} is NOT a model-bound column — it's the Issue::key()
    // accessor ("PROJ-123"). IssueController::show() parses it by hand
    // (split on the last "-", validate the prefix matches the URL's
    // project key, then resolve by project_id + number), 404-ing on any
    // malformed or mismatched input instead of erroring.
    Route::get('projects/{project:key}/issues/{issueKey}', [IssueController::class, 'show'])
        ->name('projects.issues.show');

    Route::patch('projects/{project:key}/issues/{issue}', [IssueController::class, 'update'])
        ->name('projects.issues.update')
        ->scopeBindings();

    // Comments: any member may post, but only the comment's own author may
    // edit/delete it (not even the project owner — see `CommentPolicy`).
    // {comment} is scoped to $issue->comments() via scopeBindings(), same
    // 3-level chaining as the labels routes below.
    Route::post('projects/{project:key}/issues/{issue}/comments', [CommentController::class, 'store'])
        ->name('projects.issues.comments.store')
        ->scopeBindings();
    Route::patch('projects/{project:key}/issues/{issue}/comments/{comment}', [CommentController::class, 'update'])
        ->name('projects.issues.comments.update')
        ->scopeBindings();
    Route::delete('projects/{project:key}/issues/{issue}/comments/{comment}', [CommentController::class, 'destroy'])
        ->name('projects.issues.comments.destroy')
        ->scopeBindings();

    // Labels: any member may create a project label and attach/detach it
    // on an issue (owner-only rename/delete management ships in T-10.5).
    // {label} on the detach route is scoped to $issue->labels() via
    // scopeBindings() — a label belonging to this project but not
    // currently attached to this issue 404s.
    Route::post('projects/{project:key}/labels', [LabelController::class, 'store'])
        ->name('projects.labels.store');
    Route::post('projects/{project:key}/issues/{issue}/labels', [IssueLabelController::class, 'store'])
        ->name('projects.issues.labels.store')
        ->scopeBindings();
    Route::delete('projects/{project:key}/issues/{issue}/labels/{label}', [IssueLabelController::class, 'destroy'])
        ->name('projects.issues.labels.destroy')
        ->scopeBindings();

    // Column management (owner only, enforced by BoardColumnPolicy).
    // {boardColumn} is likewise scoped to $project->boardColumns() via
    // scopeBindings(), so a column id from another project 404s.
    Route::post('projects/{project:key}/board-columns', [BoardColumnController::class, 'store'])
        ->name('projects.board-columns.store');
    Route::patch('projects/{project:key}/board-columns/{boardColumn}', [BoardColumnController::class, 'update'])
        ->name('projects.board-columns.update')
        ->scopeBindings();
    Route::patch('projects/{project:key}/board-columns/{boardColumn}/reorder', [BoardColumnController::class, 'reorder'])
        ->name('projects.board-columns.reorder')
        ->scopeBindings();
    Route::delete('projects/{project:key}/board-columns/{boardColumn}', [BoardColumnController::class, 'destroy'])
        ->name('projects.board-columns.destroy')
        ->scopeBindings();
});

require __DIR__.'/auth.php';
