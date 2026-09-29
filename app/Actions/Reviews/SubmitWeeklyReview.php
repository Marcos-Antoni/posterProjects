<?php

namespace App\Actions\Reviews;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\Operation;
use App\Enums\ReviewKind;
use App\Models\Habit;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Review;
use App\Models\WeeklyPriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The weekly review (reviews spec "The Weekly Review Shows Progress Before
 * Asking" and "The Weekly Priority Is..."): records the two questions as
 * history and sets next week's main priority plus at most two maintenance
 * standards, atomically. Skipping the review entirely blocks nothing — this
 * action is only called when the owner does submit one.
 */
class SubmitWeeklyReview
{
    public const TOO_MANY_MAINTENANCE = 'Como mucho dos estándares de mantenimiento.';

    public function __construct(private DomainTransaction $transaction) {}

    /**
     * @param  array<string, mixed>  $data  validated input (`SubmitWeeklyReviewRequest`): `what_worked`,
     *                                      `what_blocked`, `main_type`, `main_id`, `maintenance` (list of {type, id})
     */
    public function __invoke(Actor $actor, array $data): Review
    {
        $main = $this->resolve($actor, (string) $data['main_type'], (int) $data['main_id']);

        $maintenanceInput = array_values((array) ($data['maintenance'] ?? []));

        if (count($maintenanceInput) > 2) {
            throw ValidationException::withMessages(['maintenance' => self::TOO_MANY_MAINTENANCE]);
        }

        $maintenance = array_map(
            fn (array $entry): array => [
                'type' => $entry['type'],
                'id' => $this->resolve($actor, (string) $entry['type'], (int) $entry['id'])->getKey(),
            ],
            $maintenanceInput,
        );

        $review = new Review([
            'user_id' => $actor->user->id,
            'kind' => ReviewKind::Weekly,
            'answers' => [
                'what_worked' => trim((string) $data['what_worked']),
                'what_blocked' => trim((string) $data['what_blocked']),
            ],
        ]);

        return $this->transaction->run($actor, Operation::SubmitWeeklyReview, $review, function () use ($actor, $review, $data, $main, $maintenance): Review {
            $review->save();

            $week = WeeklyPriority::nextWeek();

            WeeklyPriority::query()->updateOrCreate(
                ['user_id' => $actor->user->id, 'iso_year' => $week['year'], 'iso_week' => $week['week']],
                ['main_type' => $data['main_type'], 'main_id' => $main->getKey(), 'maintenance' => $maintenance === [] ? null : $maintenance],
            );

            return $review;
        });
    }

    /**
     * @throws ValidationException
     */
    private function resolve(Actor $actor, string $type, int $id): Model
    {
        $model = match ($type) {
            'objective' => Objective::query()->where('user_id', $actor->user->id)->find($id),
            'plan' => Plan::query()->whereHas('objective', fn ($query) => $query->where('user_id', $actor->user->id))->find($id),
            'habit' => Habit::query()->where('user_id', $actor->user->id)->find($id),
            default => null,
        };

        if ($model === null) {
            throw ValidationException::withMessages(['main_id' => 'Eso no existe o no es tuyo.']);
        }

        return $model;
    }
}
