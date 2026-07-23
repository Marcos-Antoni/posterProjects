<?php

use App\Http\Controllers\BacklogController;
use App\Http\Controllers\BoardColumnController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\HabitController;
use App\Http\Controllers\HabitEntryController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\IssueLabelController;
use App\Http\Controllers\IssueMoveController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SprintController;
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

    // Global, cross-project calendar — every issue with a due date across
    // every project the authenticated user is a member of.
    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar');

    // Habits are personal to the authenticated user — never project
    // scoped. There is intentionally NO destroy route: habits can only
    // be archived (and reactivated), their history is never deleted.
    Route::get('habits/manage', [HabitController::class, 'index'])->name('habits.index');
    Route::post('habits', [HabitController::class, 'store'])->name('habits.store');
    Route::patch('habits/{habit}', [HabitController::class, 'update'])->name('habits.update');
    Route::post('habits/{habit}/archive', [HabitController::class, 'archive'])->name('habits.archive');
    Route::post('habits/{habit}/unarchive', [HabitController::class, 'unarchive'])->name('habits.unarchive');
    Route::post('habits/{habit}/entries', [HabitEntryController::class, 'store'])->name('habits.entries.store');

    // Bound by key (not id) — the board and its nested routes are
    // addressed by the project's human-readable key, e.g. /projects/DEMO/board.
    Route::get('projects/{project:key}/board', [BoardController::class, 'show'])->name('projects.board');

    // Read-only: sprints (collapsible, with story-point sums) + the
    // Backlog section (sprint_id null). Reassigning an issue reuses
    // `projects.issues.update` — see `BacklogController`.
    Route::get('projects/{project:key}/backlog', [BacklogController::class, 'index'])->name('projects.backlog');

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
    // on an issue; the management list, rename, and delete below are
    // owner only (LabelPolicy). {label} on the detach route is scoped to
    // $issue->labels() via scopeBindings() — a label belonging to this
    // project but not currently attached to this issue 404s.
    Route::get('projects/{project:key}/labels', [LabelController::class, 'index'])
        ->name('projects.labels.index');
    Route::post('projects/{project:key}/labels', [LabelController::class, 'store'])
        ->name('projects.labels.store');
    Route::patch('projects/{project:key}/labels/{label}', [LabelController::class, 'update'])
        ->name('projects.labels.update')
        ->scopeBindings();
    Route::delete('projects/{project:key}/labels/{label}', [LabelController::class, 'destroy'])
        ->name('projects.labels.destroy')
        ->scopeBindings();
    Route::post('projects/{project:key}/issues/{issue}/labels', [IssueLabelController::class, 'store'])
        ->name('projects.issues.labels.store')
        ->scopeBindings();
    Route::delete('projects/{project:key}/issues/{issue}/labels/{label}', [IssueLabelController::class, 'destroy'])
        ->name('projects.issues.labels.destroy')
        ->scopeBindings();

    // Sprint management (owner only, enforced by SprintPolicy). Deleting a
    // sprint returns its issues to the backlog via `nullOnDelete()` on
    // `issues.sprint_id` — see `SprintController::destroy()`.
    Route::post('projects/{project:key}/sprints', [SprintController::class, 'store'])
        ->name('projects.sprints.store');
    Route::patch('projects/{project:key}/sprints/{sprint}', [SprintController::class, 'update'])
        ->name('projects.sprints.update')
        ->scopeBindings();
    Route::delete('projects/{project:key}/sprints/{sprint}', [SprintController::class, 'destroy'])
        ->name('projects.sprints.destroy')
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
