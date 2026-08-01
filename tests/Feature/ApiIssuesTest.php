<?php

use App\Enums\TokenName;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Label;
use App\Models\Sprint;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The expected wire shape of one list-row issue, mirroring
 * `IssueResource::toArray()` field-for-field (including order — `toBe()`
 * is a strict `===` comparison, and PHP array equality under `===` is
 * order-sensitive). `assignee` and `labels` are read from the model's own
 * relations rather than passed in, so the payload always reflects
 * whatever the fixture actually attached.
 *
 * @return array<string, mixed>
 */
function issuePayload(Issue $issue): array
{
    return [
        'id' => $issue->id,
        'key' => $issue->key,
        'number' => $issue->number,
        'title' => $issue->title,
        'type' => $issue->type->value,
        'priority' => $issue->priority->value,
        'story_points' => $issue->story_points,
        'due_date' => $issue->due_date?->toDateString(),
        'board_column_id' => $issue->board_column_id,
        'sprint_id' => $issue->sprint_id,
        'parent_id' => $issue->parent_id,
        'position' => $issue->position,
        'assignee' => $issue->assignee === null ? null : [
            'id' => $issue->assignee->id,
            'name' => $issue->assignee->name,
        ],
        'labels' => $issue->labels->sortBy(['name', 'id'])->values()->map(fn (Label $label): array => [
            'id' => $label->id,
            'name' => $label->name,
        ])->all(),
        'updated_at' => $issue->updated_at?->toIso8601String(),
    ];
}

/**
 * The expected wire shape of the detail response: the 15 list fields plus
 * `description`, `reporter`, `parent`, `children`, `comments`, mirroring
 * `IssueDetailResource::toArray()` field-for-field, including order.
 * `children`/`comments` are sorted the same way the controller orders
 * them (`position,id` / `created_at,id`) so the expectation is correct
 * regardless of the fixture's creation order.
 *
 * @return array<string, mixed>
 */
function issueDetailPayload(Issue $issue): array
{
    return [
        ...issuePayload($issue),
        'description' => $issue->description,
        'reporter' => ['id' => $issue->reporter->id, 'name' => $issue->reporter->name],
        'parent' => $issue->parent === null ? null : [
            'id' => $issue->parent->id,
            'key' => $issue->parent->key,
            'title' => $issue->parent->title,
        ],
        'children' => $issue->children->sortBy(['position', 'id'])->values()->map(fn (Issue $child): array => [
            'id' => $child->id,
            'key' => $child->key,
            'title' => $child->title,
            'type' => $child->type->value,
            'board_column_id' => $child->board_column_id,
        ])->all(),
        'comments' => $issue->comments->sortBy(['created_at', 'id'])->values()->map(fn (Comment $comment): array => [
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
            'author' => ['id' => $comment->author->id, 'name' => $comment->author->name],
        ])->all(),
    ];
}

/**
 * Shared non-disclosure assertion for both issue endpoints: the generic
 * 404 body, never leaking `App\Models\Issue` or `App\Models\Project`.
 */
function assertIssueNotFound(TestResponse $response): void
{
    $response->assertStatus(404);
    $response->assertExactJson(['message' => 'Recurso no encontrado.']);
    expect($response->getContent())
        ->not->toContain('App\\Models\\Issue')
        ->not->toContain('App\\Models\\Project');
}

test('the list shape exposes exactly the 15 pinned fields', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'SHAPE']);
    $todo = $project->boardColumns()->orderBy('position')->first();
    $assignee = User::factory()->create(['name' => 'Ada Lovelace']);
    $label = Label::factory()->for($project)->create(['name' => 'backend']);

    $issue = Issue::factory()
        ->for($project)
        ->for($todo, 'boardColumn')
        ->create([
            'assignee_id' => $assignee->id,
            'position' => 0,
            'due_date' => '2026-08-15',
        ]);
    $issue->labels()->attach($label);
    $issue->refresh();

    $response = $this->getJson("/api/v1/projects/{$project->key}/issues", apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data'))->toBe([issuePayload($issue)]);
});

test('an issue in the third board column reports its own id and position, not the columns', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'JOIN']);
    $doneColumn = $project->boardColumns()->orderBy('position')->skip(2)->first();

    $issue = Issue::factory()->for($project)->for($doneColumn, 'boardColumn')->create(['position' => 0]);

    $response = $this->getJson("/api/v1/projects/{$project->key}/issues", apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data'))->toBe([issuePayload($issue)]);
});

test('the list order follows board column position then issue position then id, stable across two calls', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'ORDER']);
    [$todo, $inProgress, $done] = $project->boardColumns()->orderBy('position')->get()->all();

    $inDone = Issue::factory()->for($project)->for($done, 'boardColumn')->create(['position' => 0]);
    $inTodoSecond = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['position' => 1]);
    $inTodoFirst = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['position' => 0]);
    $inProgressIssue = Issue::factory()->for($project)->for($inProgress, 'boardColumn')->create(['position' => 0]);

    $expectedOrder = [$inTodoFirst->id, $inTodoSecond->id, $inProgressIssue->id, $inDone->id];

    $first = $this->getJson("/api/v1/projects/{$project->key}/issues", apiBearerHeaders($token));
    $second = $this->getJson("/api/v1/projects/{$project->key}/issues", apiBearerHeaders($token));

    $first->assertOk();
    $second->assertOk();
    expect(collect($first->json('data'))->pluck('id')->all())->toBe($expectedOrder)
        ->and(collect($second->json('data'))->pluck('id')->all())->toBe($expectedOrder);
});

test('two issues sharing a column position from different sprints are ordered by issue id', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'TIE']);
    $todo = $project->boardColumns()->orderBy('position')->first();
    $sprintA = Sprint::factory()->for($project)->create();
    $sprintB = Sprint::factory()->for($project)->create();

    $first = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['position' => 0, 'sprint_id' => $sprintA->id]);
    $second = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['position' => 0, 'sprint_id' => $sprintB->id]);

    $response = $this->getJson("/api/v1/projects/{$project->key}/issues", apiBearerHeaders($token));

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$first->id, $second->id]);
});

test('pagination retains the sprint filter across the page boundary via withQueryString', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'PAGE']);
    $todo = $project->boardColumns()->orderBy('position')->first();
    $sprint = Sprint::factory()->for($project)->create();

    $inSprintFirst = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['position' => 0, 'sprint_id' => $sprint->id]);
    $inSprintSecond = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['position' => 1, 'sprint_id' => $sprint->id]);
    // Distractor: outside the sprint filter, proves the filter survives pagination too.
    Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['position' => 2, 'sprint_id' => null]);

    $firstPage = $this->getJson(
        "/api/v1/projects/{$project->key}/issues?sprint={$sprint->id}&per_page=1",
        apiBearerHeaders($token),
    );

    $firstPage->assertOk();
    expect($firstPage->json('links'))->toHaveKeys(['first', 'last', 'prev', 'next'])
        ->and($firstPage->json('meta'))->toHaveKeys(['current_page', 'last_page', 'per_page', 'total', 'from', 'to', 'path', 'links'])
        ->and($firstPage->json('meta.current_page'))->toBe(1)
        ->and($firstPage->json('meta.last_page'))->toBe(2)
        ->and($firstPage->json('data.0.id'))->toBe($inSprintFirst->id)
        ->and($firstPage->json('links.next'))->toContain("sprint={$sprint->id}")
        ->and($firstPage->json('links.next'))->toContain('page=2');

    $secondPage = $this->getJson($firstPage->json('links.next'), apiBearerHeaders($token));

    $secondPage->assertOk();
    expect($secondPage->json('meta.current_page'))->toBe(2)
        ->and($secondPage->json('data'))->toHaveCount(1)
        ->and($secondPage->json('data.0.id'))->toBe($inSprintSecond->id);
});

test('sprint filtering and query parameter validation', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'FILT']);
    $todo = $project->boardColumns()->orderBy('position')->first();
    $sprint = Sprint::factory()->for($project)->create();
    $otherProject = createMemberProject($user, ['key' => 'OTHERF']);
    $foreignSprint = Sprint::factory()->for($otherProject)->create();

    $sprinted = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['sprint_id' => $sprint->id, 'position' => 0]);
    $backlog = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['sprint_id' => null, 'position' => 1]);

    $bySprint = $this->getJson("/api/v1/projects/{$project->key}/issues?sprint={$sprint->id}", apiBearerHeaders($token));
    $bySprint->assertOk();
    expect(collect($bySprint->json('data'))->pluck('id')->all())->toBe([$sprinted->id]);

    $byBacklog = $this->getJson("/api/v1/projects/{$project->key}/issues?sprint=backlog", apiBearerHeaders($token));
    $byBacklog->assertOk();
    expect(collect($byBacklog->json('data'))->pluck('id')->all())->toBe([$backlog->id]);

    $foreign = $this->getJson("/api/v1/projects/{$project->key}/issues?sprint={$foreignSprint->id}", apiBearerHeaders($token));
    assertIssueNotFound($foreign);

    $badSprint = $this->getJson("/api/v1/projects/{$project->key}/issues?sprint=abc", apiBearerHeaders($token));
    $badSprint->assertStatus(422);

    $badPerPage = $this->getJson("/api/v1/projects/{$project->key}/issues?per_page=101", apiBearerHeaders($token));
    $badPerPage->assertStatus(422);

    $badPage = $this->getJson("/api/v1/projects/{$project->key}/issues?page=abc", apiBearerHeaders($token));
    $badPage->assertStatus(422);
});

test('the list endpoint requires a bearer token with the mobile ability', function () {
    $user = User::factory()->create();
    $project = createMemberProject($user, ['key' => 'AUTH']);

    $noToken = $this->getJson("/api/v1/projects/{$project->key}/issues");
    $noToken->assertStatus(401);
    expect($noToken->headers->get('WWW-Authenticate'))->toStartWith('Bearer');

    $mcpToken = $user->createToken(TokenName::Mcp->value, [TokenName::Mcp->value])->plainTextToken;
    $forbidden = $this->getJson("/api/v1/projects/{$project->key}/issues", apiBearerHeaders($mcpToken));
    $forbidden->assertStatus(403);
    $forbidden->assertExactJson(['message' => 'Este token no tiene permiso para usar esta API.']);
});

test('a non members project issues are not found, never forbidden', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $stranger = User::factory()->create();
    $strangerProject = createMemberProject($stranger, ['key' => 'THEIRS']);

    $response = $this->getJson("/api/v1/projects/{$strangerProject->key}/issues", apiBearerHeaders($token));

    assertIssueNotFound($response);
});

test('an unknown project key on the list endpoint is not found', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $response = $this->getJson('/api/v1/projects/NOPE/issues', apiBearerHeaders($token));

    assertIssueNotFound($response);
});

test('an archived projects issue list is not found despite the project itself resolving with archived true', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'ARCHIVEDLIST']);
    $projectKey = $project->key;
    $project->delete();

    $projectResponse = $this->getJson("/api/v1/projects/{$projectKey}", apiBearerHeaders($token));
    $projectResponse->assertOk();
    expect($projectResponse->json('data.archived'))->toBeTrue();

    $listResponse = $this->getJson("/api/v1/projects/{$projectKey}/issues", apiBearerHeaders($token));
    assertIssueNotFound($listResponse);
});

test('the list query count does not grow with issue count, and eager-loaded values are correct', function () {
    Model::preventLazyLoading();

    try {
        $user = User::factory()->create();
        $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
        $project = createMemberProject($user, ['key' => 'NPLUS1']);
        $todo = $project->boardColumns()->orderBy('position')->first();
        $assignee = User::factory()->create(['name' => 'Grace Hopper']);
        $label = Label::factory()->for($project)->create(['name' => 'urgent']);

        $solo = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['assignee_id' => $assignee->id, 'position' => 0]);
        $solo->labels()->attach($label);

        $selectCount = 0;
        DB::listen(function ($query) use (&$selectCount): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
                $selectCount++;
            }
        });

        $this->app['auth']->forgetGuards();
        $selectCount = 0;
        $soloResponse = $this->getJson("/api/v1/projects/{$project->key}/issues", apiBearerHeaders($token));
        $soloQueries = $selectCount;

        for ($i = 0; $i < 4; $i++) {
            $issue = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['assignee_id' => $assignee->id, 'position' => $i + 1]);
            $issue->labels()->attach($label);
        }

        $this->app['auth']->forgetGuards();
        $selectCount = 0;
        $manyResponse = $this->getJson("/api/v1/projects/{$project->key}/issues", apiBearerHeaders($token));
        $manyQueries = $selectCount;

        $soloResponse->assertOk();
        $manyResponse->assertOk();
        expect($manyQueries)->toBe($soloQueries)
            ->and($manyResponse->json('data'))->toHaveCount(5);

        foreach ($manyResponse->json('data') as $issueData) {
            expect($issueData['assignee']['name'])->toBe('Grace Hopper')
                ->and($issueData['labels'][0]['name'])->toBe('urgent');
        }
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('the detail shape exposes the 20 pinned fields with every relation embedded', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'DETAIL']);
    $todo = $project->boardColumns()->orderBy('position')->first();
    $assignee = User::factory()->create(['name' => 'Ada Lovelace']);
    $reporter = User::factory()->create(['name' => 'Alan Turing']);
    $label = Label::factory()->for($project)->create(['name' => 'backend']);

    $parent = Issue::factory()->epic()->for($project)->for($todo, 'boardColumn')->create([
        'reporter_id' => $reporter->id,
        'position' => 0,
    ]);
    $issue = Issue::factory()->for($project)->for($todo, 'boardColumn')->create([
        'parent_id' => $parent->id,
        'assignee_id' => $assignee->id,
        'reporter_id' => $reporter->id,
        'position' => 1,
    ]);
    $issue->labels()->attach($label);
    Issue::factory()->for($project)->for($todo, 'boardColumn')->create([
        'parent_id' => $issue->id,
        'reporter_id' => $reporter->id,
        'position' => 2,
    ]);
    $commenter = User::factory()->create(['name' => 'Grace Hopper']);
    Comment::factory()->for($issue)->for($commenter, 'author')->create();

    $freshIssue = $issue->fresh(['labels', 'assignee', 'reporter', 'parent', 'children', 'comments.author']);

    $response = $this->getJson("/api/v1/projects/{$project->key}/issues/{$issue->key}", apiBearerHeaders($token));

    $response->assertOk();
    expect($response->json('data'))->toBe(issueDetailPayload($freshIssue));
});

test('comment order is stable when two comments share the same created_at second', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'CMT']);
    $todo = $project->boardColumns()->orderBy('position')->first();
    $issue = Issue::factory()->for($project)->for($todo, 'boardColumn')->create(['position' => 0]);
    $author = User::factory()->create();

    $tick = now();
    $first = Comment::factory()->for($issue)->for($author, 'author')->create(['created_at' => $tick, 'updated_at' => $tick]);
    $second = Comment::factory()->for($issue)->for($author, 'author')->create(['created_at' => $tick, 'updated_at' => $tick]);

    $response = $this->getJson("/api/v1/projects/{$project->key}/issues/{$issue->key}", apiBearerHeaders($token));

    $response->assertOk();
    expect(collect($response->json('data.comments'))->pluck('id')->all())->toBe([$first->id, $second->id]);
});

test('an unknown project key on the detail endpoint is not found', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $response = $this->getJson('/api/v1/projects/NOPE/issues/NOPE-1', apiBearerHeaders($token));

    assertIssueNotFound($response);
});

test('a non members project key on the detail endpoint is not found', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $stranger = User::factory()->create();
    $strangerProject = createMemberProject($stranger, ['key' => 'THEIRS2']);
    $strangerIssue = Issue::factory()->for($strangerProject)->for($strangerProject->boardColumns->first(), 'boardColumn')->create();

    $response = $this->getJson("/api/v1/projects/{$strangerProject->key}/issues/{$strangerIssue->key}", apiBearerHeaders($token));

    assertIssueNotFound($response);
});

test('an archived projects issues are not found despite the project itself resolving with archived true', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'ARCHIVED2']);
    $issue = Issue::factory()->for($project)->for($project->boardColumns->first(), 'boardColumn')->create();
    $issueKey = $issue->key;
    $projectKey = $project->key;
    $project->delete();

    $projectResponse = $this->getJson("/api/v1/projects/{$projectKey}", apiBearerHeaders($token));
    $projectResponse->assertOk();
    expect($projectResponse->json('data.archived'))->toBeTrue();

    $issuesResponse = $this->getJson("/api/v1/projects/{$projectKey}/issues/{$issueKey}", apiBearerHeaders($token));
    assertIssueNotFound($issuesResponse);
});

test('a key with no dash on the detail endpoint is not found', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'NODASH']);

    $response = $this->getJson("/api/v1/projects/{$project->key}/issues/nodash", apiBearerHeaders($token));

    assertIssueNotFound($response);
});

test('a prefix mismatch on the detail endpoint is not found', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'PROJ']);
    $other = createMemberProject($user, ['key' => 'OTHER']);
    $otherIssue = Issue::factory()->for($other)->for($other->boardColumns->first(), 'boardColumn')->create();

    $response = $this->getJson("/api/v1/projects/{$project->key}/issues/{$otherIssue->key}", apiBearerHeaders($token));

    assertIssueNotFound($response);
});

test('a non numeric or empty suffix on the detail endpoint is not found', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'BADSUF']);

    $nonNumeric = $this->getJson("/api/v1/projects/{$project->key}/issues/{$project->key}-abc", apiBearerHeaders($token));
    assertIssueNotFound($nonNumeric);

    $empty = $this->getJson("/api/v1/projects/{$project->key}/issues/{$project->key}-", apiBearerHeaders($token));
    assertIssueNotFound($empty);
});

test('an unknown issue number in this project is not found', function () {
    $user = User::factory()->create();
    $token = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;
    $project = createMemberProject($user, ['key' => 'UNKNOWNNUM']);

    $response = $this->getJson("/api/v1/projects/{$project->key}/issues/{$project->key}-999", apiBearerHeaders($token));

    assertIssueNotFound($response);
});
