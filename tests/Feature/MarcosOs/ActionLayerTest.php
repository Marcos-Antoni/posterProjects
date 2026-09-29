<?php

use App\Actions\Support\Actor;
use App\Actions\Support\ActorKind;
use App\Actions\Support\AiTier;
use App\Actions\Support\AuditWriter;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\MajorOperationRequiresProposal;
use App\Actions\Support\Operation;
use App\Actions\Support\TierGate;
use App\Models\Item;
use App\Models\Objective;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/*
| Task 2.6 — the `Actor` value object and the domain action layer skeleton
| (design D3): one `TierGate` that lets owner actors through and classifies AI
| operations (ai-operations spec), and an audit writer that records every
| AI-applied change inside the same transaction.
*/

/**
 * An in-memory AuditWriter so the tests can see what would be audited.
 */
function mosFakeAuditWriter(): AuditWriter
{
    $writer = new class implements AuditWriter
    {
        /** @var list<array<string, mixed>> */
        public array $entries = [];

        public function record(Actor $actor, Operation $operation, Model $target, array $before, array $after): void
        {
            $this->entries[] = compact('actor', 'operation', 'target', 'before', 'after');
        }
    };

    app()->instance(AuditWriter::class, $writer);

    return $writer;
}

test('actors name who acts: two owner surfaces and two AI surfaces', function () {
    $user = User::factory()->make();

    expect(Actor::ownerWeb($user)->kind)->toBe(ActorKind::OwnerWeb)
        ->and(Actor::ownerApi($user)->kind)->toBe(ActorKind::OwnerApi)
        ->and(Actor::aiMcp($user)->kind)->toBe(ActorKind::AiMcp)
        ->and(Actor::aiBridge($user)->kind)->toBe(ActorKind::AiBridge)
        ->and(Actor::ownerWeb($user)->isOwner())->toBeTrue()
        ->and(Actor::ownerApi($user)->isAi())->toBeFalse()
        ->and(Actor::aiMcp($user)->isAi())->toBeTrue()
        ->and(Actor::aiBridge($user)->user)->toBe($user);
});

test('only the minor list is minor; every other operation is major', function () {
    expect(Operation::CheckItem->tier())->toBe(AiTier::Minor)
        ->and(Operation::UncheckItem->tier())->toBe(AiTier::Minor)
        ->and(Operation::UpdateMetricCurrent->tier())->toBe(AiTier::Minor)
        ->and(Operation::AddItem->tier())->toBe(AiTier::Major)
        ->and(Operation::UpdatePlan->tier())->toBe(AiTier::Major)
        ->and(Operation::AddDependency->tier())->toBe(AiTier::Major)
        ->and(Operation::CreateObjective->tier())->toBe(AiTier::Major);
});

test('owner actors pass through the gate for any operation', function (Operation $operation) {
    $gate = app(TierGate::class);

    $gate->authorize(Actor::ownerWeb(User::factory()->make()), $operation);
    $gate->authorize(Actor::ownerApi(User::factory()->make()), $operation);

    expect(true)->toBeTrue();
})->with(Operation::cases());

test('an AI actor may apply a minor operation', function () {
    app(TierGate::class)->authorize(Actor::aiMcp(User::factory()->make()), Operation::CheckItem);

    expect(true)->toBeTrue();
});

test('an AI actor cannot apply a major operation without a grant', function () {
    app(TierGate::class)->authorize(Actor::aiMcp(User::factory()->make()), Operation::AddItem);
})->throws(MajorOperationRequiresProposal::class, 'propose-change');

test('an AI-applied change is audited with before and after, inside the transaction', function () {
    $audit = mosFakeAuditWriter();
    $item = Item::factory()->create();
    $actor = Actor::aiMcp($item->objective->user);

    app(DomainTransaction::class)->run($actor, Operation::CheckItem, $item, function () use ($item): void {
        $item->update(['completed_at' => now()]);
    });

    expect($audit->entries)->toHaveCount(1)
        ->and($audit->entries[0]['operation'])->toBe(Operation::CheckItem)
        ->and($audit->entries[0]['target']->is($item))->toBeTrue()
        ->and($audit->entries[0]['before']['completed_at'])->toBeNull()
        ->and($audit->entries[0]['after']['completed_at'])->not->toBeNull();
});

test('owner changes are not written to the AI audit', function () {
    $audit = mosFakeAuditWriter();
    $objective = Objective::factory()->create();

    app(DomainTransaction::class)->run(Actor::ownerWeb($objective->user), Operation::UpdateObjective, $objective, function () use ($objective): void {
        $objective->update(['title' => 'Otro título']);
    });

    expect($audit->entries)->toBe([]);
});

test('a failed change rolls back and writes no audit entry', function () {
    $audit = mosFakeAuditWriter();
    $item = Item::factory()->create();

    try {
        app(DomainTransaction::class)->run(Actor::aiMcp($item->objective->user), Operation::CheckItem, $item, function () use ($item): void {
            $item->update(['completed_at' => now()]);

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        //
    }

    expect($item->fresh()->completed_at)->toBeNull()
        ->and($audit->entries)->toBe([]);
});

test('a blocked major AI operation changes nothing', function () {
    $objective = Objective::factory()->create(['title' => 'Original']);

    expect(fn () => app(DomainTransaction::class)->run(Actor::aiMcp($objective->user), Operation::UpdateObjective, $objective, function () use ($objective): void {
        $objective->update(['title' => 'Cambiado por la IA']);
    }))->toThrow(MajorOperationRequiresProposal::class);

    expect($objective->fresh()->title)->toBe('Original');
});
