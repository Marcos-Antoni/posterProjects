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

test('propose no longer accepts a structural kind (2026-09-29 decision: those are direct MCP tools now)', function (string $kind) {
    $user = User::factory()->create();

    $response = PosterServer::actingAs($user)->tool(Propose::class, [
        'kind' => $kind,
        'payload' => ['key' => 'AIOBJ'],
        'summary' => 'Debería rechazarse: ya no es un kind de propose.',
    ]);

    $response->assertHasErrors(['Tipo de propuesta no soportado']);

    expect(AiProposal::query()->count())->toBe(0);
})->with(['create_objective', 'create_plan', 'add_items', 'update_item', 'add_dependency']);
