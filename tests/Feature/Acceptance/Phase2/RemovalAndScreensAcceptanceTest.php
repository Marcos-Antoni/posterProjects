<?php

/*
| Phase 2 acceptance (independent tester) — REMOVED specs (sprints, backlog,
| board, labels, comments, calendar, api-board-sprints, api-labels, the
| removed parts of projects/issues/mcp-server) and the Phase-2 screens'
| server side: no dead routes/classes/pages, web routes of the retired
| surface are 404, and the screens do not N+1.
*/

use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

function p2sQueries(Closure $request): int
{
    Model::preventLazyLoading();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    Model::preventLazyLoading(false);

    return $count;
}

test('retired web pages are 404 for the owner', function (string $uri) {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get($uri)->assertNotFound();
})->with([
    '/projects', '/projects/trash', '/projects/SALUD/board', '/projects/SALUD/backlog', '/projects/SALUD/labels',
    '/projects/SALUD/issues/SALUD-1', '/calendar', '/dashboard',
]);

test('legacy classes, pages and frontend types are gone', function () {
    foreach (['Project', 'Issue', 'Sprint', 'Label', 'BoardColumn', 'Comment'] as $model) {
        expect(File::exists(app_path("Models/{$model}.php")))->toBeFalse("App\\Models\\{$model} should be removed");
    }
    foreach (['ProjectController', 'IssueController', 'SprintController', 'BoardController', 'BacklogController', 'CalendarController', 'LabelController', 'CommentController'] as $controller) {
        expect(File::exists(app_path("Http/Controllers/{$controller}.php")))->toBeFalse();
    }

    expect(File::exists(resource_path('js/pages/projects')))->toBeFalse()
        ->and(File::exists(resource_path('js/pages/calendar.tsx')))->toBeFalse();

    $mcpTools = collect(File::allFiles(app_path('Mcp/Tools')))->map(fn ($file) => $file->getRelativePath())->unique()->values()->all();
    expect($mcpTools)->not->toContain('Projects', 'Issues', 'Sprints', 'Labels', 'Comments', 'BoardColumns', 'Views');
});

test('the objectives index, the tree, the plan and the item screens do not N+1', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $build = function (string $key) use ($owner): Plan {
        $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => $key]);
        $plan = Plan::factory()->for($objective)->withControlPlan()->create();
        $first = Item::factory()->for($plan)->create();
        $second = Item::factory()->for($plan)->create();
        ItemDependency::query()->create(['prerequisite_id' => $first->id, 'dependent_id' => $second->id]);

        return $plan;
    };

    $plan = $build('UNO');
    $this->get('/objectives')->assertOk(); // warm

    $counts = fn () => [
        'index' => p2sQueries(fn () => $this->get('/objectives')->assertOk()),
        'tree' => p2sQueries(fn () => $this->get('/objectives/UNO')->assertOk()),
        'plan' => p2sQueries(fn () => $this->get("/objectives/UNO/plans/{$plan->id}")->assertOk()),
        'item' => p2sQueries(fn () => $this->get('/objectives/UNO/items/UNO-1')->assertOk()),
    ];

    $before = $counts();

    foreach (['DOS', 'TRES', 'CUATRO', 'CINCO'] as $key) {
        $build($key);
    }
    foreach (range(1, 4) as $i) {
        $extraPlan = Plan::factory()->for($plan->objective)->withControlPlan()->create();
        $dependent = Item::factory()->for($extraPlan)->create();
        ItemDependency::query()->create(['prerequisite_id' => Item::query()->where('plan_id', $plan->id)->first()->id, 'dependent_id' => $dependent->id]);
        Item::factory()->for($plan)->create();
    }

    expect($counts())->toBe($before);
});

test('the item screen carries the full item payload: plan, objective, prerequisites and unlocks with state', function () {
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'SALUD']);
    $plan = Plan::factory()->for($objective)->create(['title' => 'Base']);
    $a = Item::factory()->for($plan)->create(['title' => 'A']);
    $b = Item::factory()->for($plan)->create(['title' => 'B', 'kind' => 'milestone']);
    $c = Item::factory()->for($plan)->create(['title' => 'C']);
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);
    ItemDependency::query()->create(['prerequisite_id' => $b->id, 'dependent_id' => $c->id]);

    $this->actingAs($owner)->get('/objectives/SALUD/items/SALUD-2')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('items/show')
        ->where('objective.key', 'SALUD')
        ->where('item.key', 'SALUD-2')
        ->where('item.kind', 'milestone')
        ->where('item.state', 'locked')
        ->where('item.plan.title', 'Base')
        ->where('item.prerequisites.0.key', 'SALUD-1')
        ->where('item.prerequisites.0.state', 'available')
        ->where('item.unlocks.0.key', 'SALUD-3')
        ->where('item.unlocks.0.state', 'locked')
        ->has('item.two_minute_version')
        ->has('item.completed_at')
        ->has('item.evidence'));
});

test('root and login land on the objectives index', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get('/')->assertRedirect('/objectives');
});
