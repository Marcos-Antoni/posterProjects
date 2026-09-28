<?php

namespace App\Actions\Habits;

use App\Actions\Support\Actor;
use App\Enums\HabitType;
use App\Enums\ObjectiveState;
use App\Enums\PlanState;
use App\Enums\RecurrenceType;
use App\Models\Habit;
use App\Models\Objective;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;

/**
 * Turns validated habit input into the attributes `CreateHabit` and
 * `UpdateHabit` persist, enforcing the domain rules every surface shares
 * (web, MCP): the 2-minute version is required, the objective/plan link is
 * the owner's and not retired (a plan must belong to the linked objective),
 * and the level ladder stays consistent with the habit's current level.
 */
class HabitWriter
{
    public const MISSING_TWO_MINUTE = 'Falta la versión de 2 minutos. Es lo que vas a hacer los días difíciles.';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function attributes(Actor $actor, array $data, ?Habit $habit = null): array
    {
        $twoMinute = trim((string) ($data['two_minute_version'] ?? ''));

        if ($twoMinute === '') {
            throw ValidationException::withMessages(['two_minute_version' => self::MISSING_TWO_MINUTE]);
        }

        $type = $data['habit_type'] instanceof HabitType ? $data['habit_type'] : HabitType::from((string) $data['habit_type']);
        $recurrence = $data['recurrence_type'] instanceof RecurrenceType ? $data['recurrence_type'] : RecurrenceType::from((string) $data['recurrence_type']);
        $identity = trim((string) ($data['identity_statement'] ?? ''));

        $attributes = [
            'name' => trim((string) $data['name']),
            'two_minute_version' => $twoMinute,
            'identity_statement' => $identity === '' ? null : $identity,
            'habit_type' => $type,
            'unit' => $type === HabitType::Quantitative ? ($data['unit'] ?? null) : null,
            'daily_target' => $type === HabitType::Quantitative && isset($data['daily_target']) ? (int) $data['daily_target'] : null,
            'recurrence_type' => $recurrence,
            'weekdays' => $recurrence === RecurrenceType::SpecificWeekdays ? array_values(array_map('intval', (array) ($data['weekdays'] ?? []))) : null,
            'times_per_week' => $recurrence === RecurrenceType::TimesPerWeek && isset($data['times_per_week']) ? (int) $data['times_per_week'] : null,
            'planned_time' => filled($data['planned_time'] ?? null) ? $data['planned_time'] : null,
            ...$this->link($actor, $data, $habit),
        ];

        return [...$attributes, ...$this->ladder($data, $attributes, $habit)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{objective_id: int|null, plan_id: int|null}
     */
    private function link(Actor $actor, array $data, ?Habit $habit): array
    {
        $objectiveId = filled($data['objective_id'] ?? null) ? (int) $data['objective_id'] : null;
        $planId = filled($data['plan_id'] ?? null) ? (int) $data['plan_id'] : null;

        if ($objectiveId === null) {
            if ($planId !== null) {
                throw ValidationException::withMessages(['plan_id' => 'Elegí primero el objetivo del plan.']);
            }

            return ['objective_id' => null, 'plan_id' => null];
        }

        $objective = Objective::query()->whereKey($objectiveId)->where('user_id', $actor->user->id)->first();
        $keepsLink = $habit !== null && $habit->objective_id === $objectiveId;

        if ($objective === null || ($objective->state === ObjectiveState::Retired && ! $keepsLink)) {
            throw ValidationException::withMessages(['objective_id' => 'Ese objetivo no existe o está retirado.']);
        }

        if ($objective->state === ObjectiveState::Closed && ! $keepsLink) {
            throw ValidationException::withMessages(['objective_id' => 'Ese objetivo está cerrado: un hábito nuevo cuelga de un objetivo en curso.']);
        }

        if ($planId === null) {
            return ['objective_id' => $objective->id, 'plan_id' => null];
        }

        $plan = Plan::query()->whereKey($planId)->where('objective_id', $objective->id)->first();
        $keepsPlan = $habit !== null && $habit->plan_id === $planId;

        if ($plan === null || ($plan->state === PlanState::Retired && ! $keepsPlan)) {
            throw ValidationException::withMessages(['plan_id' => 'El plan tiene que ser de ese objetivo y no estar retirado.']);
        }

        return ['objective_id' => $objective->id, 'plan_id' => $plan->id];
    }

    /**
     * The level ladder: each level has a label, an optional target and its
     * own 2-minute version. The current level mirrors the habit's own
     * 2-minute version and (quantitative) daily target. A new or changed
     * current level starts today, so a level-up is only suggested after 14
     * days lived at it.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     * @return array{level: int|null, level_ladder: list<array{label: string, target: int|null, two_minute_version: string}>|null, level_started_on: string|null}
     */
    private function ladder(array $data, array $attributes, ?Habit $habit): array
    {
        /** @var list<array<string, mixed>> $steps */
        $steps = array_values(array_filter((array) ($data['level_ladder'] ?? []), is_array(...)));

        if ($steps === []) {
            return ['level' => null, 'level_ladder' => null, 'level_started_on' => null];
        }

        $level = (int) ($data['level'] ?? $habit->level ?? 1);

        if ($level < 1 || $level > count($steps)) {
            throw ValidationException::withMessages(['level' => 'El nivel actual tiene que ser uno de la escalera.']);
        }

        $quantitativeTarget = $attributes['habit_type'] === HabitType::Quantitative ? $attributes['daily_target'] : null;
        $ladder = [];

        foreach ($steps as $index => $step) {
            $current = $index === $level - 1;
            $target = filled($step['target'] ?? null) ? (int) $step['target'] : null;

            $ladder[] = [
                'label' => trim((string) ($step['label'] ?? '')),
                'target' => $current && $quantitativeTarget !== null ? (int) $quantitativeTarget : $target,
                'two_minute_version' => $current ? (string) $attributes['two_minute_version'] : trim((string) ($step['two_minute_version'] ?? '')),
            ];
        }

        $sameLevel = $habit !== null && $habit->level === $level && $habit->level_started_on !== null;

        return [
            'level' => $level,
            'level_ladder' => $ladder,
            'level_started_on' => $sameLevel ? $habit->level_started_on->toDateString() : Habit::todayLocalDate()->toDateString(),
        ];
    }
}
