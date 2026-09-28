<?php

namespace App\Actions\Habits;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\Operation;
use App\Models\Habit;

/**
 * Creates a habit for the actor's owner (habits spec): always with its
 * 2-minute version, optionally hanging from an objective and one of its
 * plans, with an optional identity statement and level ladder. Major for AI.
 */
class CreateHabit
{
    public function __construct(
        private DomainTransaction $transaction,
        private HabitWriter $writer,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Actor $actor, array $data): Habit
    {
        $attributes = $this->writer->attributes($actor, $data);

        return $this->transaction->run($actor, Operation::CreateHabit, new Habit, fn (): Habit => Habit::query()->create([
            ...$attributes,
            'user_id' => $actor->user->id,
        ]));
    }
}
