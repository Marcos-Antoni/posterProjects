<?php

namespace Database\Factories;

use App\Enums\HabitType;
use App\Enums\RecurrenceType;
use App\Models\Habit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Habit>
 */
class HabitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Defaults to the simplest valid habit (yes/no, daily) so tests opt
     * into type/recurrence specifics through the explicit states below.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'habit_type' => HabitType::YesNo,
            'unit' => null,
            'daily_target' => null,
            'recurrence_type' => RecurrenceType::Daily,
            'weekdays' => null,
            'times_per_week' => null,
            'planned_time' => null,
            'archived_at' => null,
        ];
    }

    public function yesNo(): static
    {
        return $this->state(fn (): array => [
            'habit_type' => HabitType::YesNo,
            'unit' => null,
            'daily_target' => null,
        ]);
    }

    public function quantitative(string $unit = 'pages', int $dailyTarget = 20): static
    {
        return $this->state(fn (): array => [
            'habit_type' => HabitType::Quantitative,
            'unit' => $unit,
            'daily_target' => $dailyTarget,
        ]);
    }

    public function daily(): static
    {
        return $this->state(fn (): array => [
            'recurrence_type' => RecurrenceType::Daily,
            'weekdays' => null,
            'times_per_week' => null,
        ]);
    }

    /**
     * @param  list<int>  $weekdays  ISO-8601 weekday numbers (1 = Monday .. 7 = Sunday).
     */
    public function specificWeekdays(array $weekdays = [1, 3, 5]): static
    {
        return $this->state(fn (): array => [
            'recurrence_type' => RecurrenceType::SpecificWeekdays,
            'weekdays' => $weekdays,
            'times_per_week' => null,
        ]);
    }

    public function timesPerWeek(int $times = 3): static
    {
        return $this->state(fn (): array => [
            'recurrence_type' => RecurrenceType::TimesPerWeek,
            'weekdays' => null,
            'times_per_week' => $times,
        ]);
    }

    public function plannedAt(string $time = '07:30:00'): static
    {
        return $this->state(fn (): array => ['planned_time' => $time]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
