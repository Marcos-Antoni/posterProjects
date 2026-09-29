<?php

use App\Enums\ProposalStatus;
use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Items\CheckItem;
use App\Mcp\Tools\Items\StartItem;
use App\Mcp\Tools\Proposals\Propose;
use App\Models\AiAuditLog;
use App\Models\AiProposal;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/*
| Phase 8 (trimmed slice, ai-operations spec): a major AI operation becomes
| a pending proposal over MCP; Marco decides it from "Propuestas" on the
| web, which reuses the same domain actions the web/MCP already call. Every
| AI-applied change — a minor MCP op or a decided proposal — is audited.
*/

test('propose over MCP creates a pending proposal and returns its web URL', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create();
    $plan = Plan::factory()->for($objective)->create();
    $item = Item::factory()->for($plan)->create();

    $response = PosterServer::actingAs($user)->tool(Propose::class, [
        'kind' => 'retire',
        'payload' => [
            'target_type' => 'item',
            'objective_key' => $objective->key,
            'item_key' => $item->key,
            'reason' => 'Se superpone con otra tarea equivalente.',
        ],
        'summary' => 'Retirar "'.$item->title.'": se superpone con otra tarea.',
    ]);

    $response->assertOk();

    $proposal = AiProposal::query()->where('user_id', $user->id)->sole();

    expect($proposal->kind)->toBe('retire')
        ->and($proposal->status)->toBe(ProposalStatus::Pending)
        ->and($proposal->source)->toBe('mcp')
        ->and($proposal->payload['item_key'])->toBe($item->key)
        ->and($item->fresh()->retired_at)->toBeNull();

    $response->assertSee(route('ai.proposals.index').'#proposal-'.$proposal->id);
});

test('accepting a pending proposal applies it exactly as stored and audits the decision', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create();
    $plan = Plan::factory()->for($objective)->create();
    $item = Item::factory()->for($plan)->create();

    $proposal = AiProposal::factory()->for($user)->create([
        'kind' => 'retire',
        'payload' => [
            'target_type' => 'item',
            'objective_key' => $objective->key,
            'item_key' => $item->key,
            'reason' => 'Se superpone con otra tarea equivalente.',
        ],
        'summary' => 'Retirar la tarea duplicada.',
    ]);

    $this->actingAs($user)
        ->post(route('ai.proposals.accept', $proposal))
        ->assertRedirect();

    expect($item->fresh()->retired_at)->not->toBeNull()
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Accepted)
        ->and($proposal->fresh()->decided_at)->not->toBeNull();

    $audit = AiAuditLog::query()->where('proposal_id', $proposal->id)->sole();

    expect($audit->tier)->toBe('major')
        ->and($audit->operation)->toBe('retire')
        ->and($audit->target_type)->toBe('item')
        ->and($audit->target_id)->toBe($item->id)
        ->and($audit->after['status'])->toBe('accepted');
});

test('rejecting a pending proposal never applies it, and the rejection is audited too', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create();
    $plan = Plan::factory()->for($objective)->create();
    $item = Item::factory()->for($plan)->create();

    $proposal = AiProposal::factory()->for($user)->create([
        'kind' => 'retire',
        'payload' => [
            'target_type' => 'item',
            'objective_key' => $objective->key,
            'item_key' => $item->key,
            'reason' => 'Se superpone con otra tarea equivalente.',
        ],
    ]);

    $this->actingAs($user)
        ->post(route('ai.proposals.reject', $proposal))
        ->assertRedirect();

    expect($item->fresh()->retired_at)->toBeNull()
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Rejected)
        ->and($proposal->fresh()->decided_at)->not->toBeNull();

    $audit = AiAuditLog::query()->where('proposal_id', $proposal->id)->sole();

    expect($audit->after['status'])->toBe('rejected')
        ->and($audit->target_type)->toBeNull();
});

test('another user cannot see or decide someone else\'s proposal', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $proposal = AiProposal::factory()->for($owner)->create();

    $this->actingAs($stranger)
        ->get(route('ai.proposals.index'))
        ->assertInertia(fn ($page) => $page->where('pending', []));

    $this->actingAs($stranger)
        ->post(route('ai.proposals.accept', $proposal))
        ->assertNotFound();

    $this->actingAs($stranger)
        ->post(route('ai.proposals.reject', $proposal))
        ->assertNotFound();

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Pending);
});

test('an existing minor MCP tool (check-item) writes an ai_audit_log row', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create();
    $plan = Plan::factory()->for($objective)->create();
    $item = Item::factory()->for($plan)->create();

    PosterServer::actingAs($user)->tool(CheckItem::class, [
        'objective_key' => $objective->key,
        'item_key' => $item->key,
    ])->assertOk();

    $audit = AiAuditLog::query()->where('user_id', $user->id)->sole();

    expect($audit->tier)->toBe('minor')
        ->and($audit->operation)->toBe('check-item')
        ->and($audit->source)->toBe('ai-mcp')
        ->and($audit->target_type)->toBe('item')
        ->and($audit->target_id)->toBe($item->id);
});

test('start-item is a minor MCP tool (phase 8): an AI actor starts an available item directly, and it is audited', function () {
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create();
    $plan = Plan::factory()->for($objective)->create();
    $item = Item::factory()->for($plan)->create();

    PosterServer::actingAs($user)->tool(StartItem::class, [
        'objective_key' => $objective->key,
        'item_key' => $item->key,
    ])->assertOk();

    expect($item->fresh()->is_active)->toBeTrue();

    $audit = AiAuditLog::query()->where('user_id', $user->id)->sole();

    expect($audit->tier)->toBe('minor')
        ->and($audit->operation)->toBe('start-item')
        ->and($audit->source)->toBe('ai-mcp')
        ->and($audit->target_type)->toBe('item')
        ->and($audit->target_id)->toBe($item->id);
});

test('propose create_objective, once accepted, creates the objective, its control plan, plans, items and dependencies in one go', function () {
    $user = User::factory()->create();

    $payload = [
        ...mosObjectiveData(['key' => 'AIOBJ']),
        'plans' => [
            [
                'title' => 'Semana 1',
                'items' => [
                    ['title' => 'Investigar', 'two_minute_version' => 'Abrir el navegador'],
                    ['title' => 'Escribir informe', 'kind' => 'milestone', 'two_minute_version' => 'Abrir el editor'],
                ],
            ],
        ],
        'dependencies' => [
            ['prerequisite' => 0, 'dependent' => 1],
        ],
    ];

    $response = PosterServer::actingAs($user)->tool(Propose::class, [
        'kind' => 'create_objective',
        'payload' => $payload,
        'summary' => 'Crear el objetivo AIOBJ con su plan de la semana 1.',
    ]);

    $response->assertOk();

    $proposal = AiProposal::query()->where('user_id', $user->id)->sole();

    expect($proposal->kind)->toBe('create_objective')
        ->and($proposal->status)->toBe(ProposalStatus::Pending)
        ->and(Objective::query()->count())->toBe(0);

    $this->actingAs($user)
        ->post(route('ai.proposals.accept', $proposal))
        ->assertRedirect();

    $objective = Objective::query()->where('key', 'AIOBJ')->sole();
    $plan = $objective->plans()->sole();
    $items = $plan->items()->orderBy('id')->get();

    expect($objective->controlPlan)->not->toBeNull()
        ->and($objective->controlPlan->outcome)->not->toBeNull()
        ->and($plan->title)->toBe('Semana 1')
        ->and($items)->toHaveCount(2)
        ->and($items[1]->prerequisites->pluck('id')->all())->toBe([$items[0]->id]);

    $audit = AiAuditLog::query()->where('proposal_id', $proposal->id)->sole();

    expect($audit->target_type)->toBe('objective')
        ->and($audit->target_id)->toBe($objective->id);
});

test('propose create_objective with an incomplete control plan is rejected at propose time, nothing written', function () {
    $user = User::factory()->create();

    $response = PosterServer::actingAs($user)->tool(Propose::class, [
        'kind' => 'create_objective',
        'payload' => [
            'key' => 'AIBAD',
            'title' => 'Objetivo incompleto',
            // outcome/deadline/metric/risks/contingency are missing on purpose.
        ],
        'summary' => 'Crear un objetivo sin plan de control (debería rechazarse).',
    ]);

    $response->assertHasErrors(['Falta el resultado']);

    expect(AiProposal::query()->count())->toBe(0)
        ->and(Objective::query()->where('key', 'AIBAD')->exists())->toBeFalse();
});
