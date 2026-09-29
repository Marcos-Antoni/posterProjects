<?php

namespace App\Actions\Captures;

use App\Actions\Habits\CreateHabit;
use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsCaptures;
use App\Actions\Support\Operation;
use App\Models\Capture;
use App\Models\Habit;

/**
 * Triage: converts a capture into a habit (capture-inbox spec). The capture
 * keeps a link to the new habit; the habit itself is created exactly like
 * the habit form (`CreateHabit`), 2-minute version required.
 */
class ConvertCaptureToHabit
{
    use GuardsCaptures;

    public function __construct(
        private DomainTransaction $transaction,
        private CreateHabit $createHabit,
    ) {}

    /**
     * @param  array<string, mixed>  $habitData  validated input (`StoreHabitRequest` shape)
     */
    public function __invoke(Actor $actor, Capture $capture, array $habitData): Habit
    {
        $this->ensureUntriaged($actor, $capture);

        return $this->transaction->run($actor, Operation::ConvertCaptureToHabit, $capture, function () use ($actor, $capture, $habitData): Habit {
            $habit = ($this->createHabit)($actor, $habitData);

            $capture->update(['triaged_at' => now(), 'result_type' => 'habit', 'result_id' => $habit->id]);

            return $habit;
        });
    }
}
