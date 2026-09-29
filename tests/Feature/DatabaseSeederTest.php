<?php

use App\Enums\ControlZone;
use App\Enums\ItemKind;
use App\Enums\ItemState;
use App\Enums\ObjectiveState;
use App\Models\Item;
use App\Models\Objective;
use App\Models\User;

test('the database seeder creates a realistic Marcos OS tree with integrity', function () {
    $this->seed();

    $owner = User::where('email', 'test@example.com')->firstOrFail();
    $objective = Objective::where('key', 'DIARIO')->firstOrFail();

    expect($objective->user_id)->toBe($owner->id)
        ->and($objective->state)->toBe(ObjectiveState::Active)
        ->and($objective->controlPlan->isComplete())->toBeTrue()
        ->and($objective->controlMapEntries->pluck('zone')->unique()->sort()->values()->all())
        ->toEqualCanonicalizing([ControlZone::Mine, ControlZone::Influence, ControlZone::Outside]);

    $items = Item::query()->withState()->where('objective_id', $objective->id)->orderBy('number')->get();

    // Sequential numbering without gaps: DIARIO-1..DIARIO-N.
    expect($items->pluck('number')->all())->toBe(range(1, $items->count()))
        ->and($items->map->key->all())->toBe(collect(range(1, $items->count()))->map(fn (int $n): string => "DIARIO-{$n}")->all());

    // Every derived state but active/retired is represented, and a milestone closes the plan.
    expect($items->pluck('state')->unique()->values()->all())->toEqualCanonicalizing([ItemState::Done, ItemState::Available, ItemState::Locked])
        ->and($items->last()->kind)->toBe(ItemKind::Milestone);

    expect(Objective::query()->where('user_id', $owner->id)->active()->pluck('key')->all())->toBe(['DIARIO', 'FINALES']);
});
