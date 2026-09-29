<?php

namespace App\Mcp\Support;

use App\Enums\HabitType;
use App\Enums\RecurrenceType;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Response;

/**
 * The input of create-habit and update-habit: the web form's fields, with
 * the objective named by its key. Resolves the key among the caller's
 * objectives and hands the web Form Request an `objective_id`.
 */
trait HabitInputSchema
{
    /**
     * @return array<string, Type>
     */
    protected function habitSchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Habit name.')
                ->required(),
            'two_minute_version' => $schema->string()
                ->description('The 2-minute version: the smallest physical start of the habit (e.g. "Abrir el libro en el separador"). Required.')
                ->required(),
            'habit_type' => $schema->string()
                ->description('How completion is measured.')
                ->enum(HabitType::class)
                ->required(),
            'unit' => $schema->string()
                ->description('Unit of measurement (e.g. "pages"). Required when habit_type is "quantitative"; ignored otherwise.'),
            'daily_target' => $schema->integer()
                ->description('Daily target amount. Required when habit_type is "quantitative"; ignored otherwise.'),
            'recurrence_type' => $schema->string()
                ->description('How often the habit is expected.')
                ->enum(RecurrenceType::class)
                ->required(),
            'weekdays' => $schema->array()
                ->items($schema->integer()->min(1)->max(7))
                ->description('ISO-8601 weekdays (1=Monday..7=Sunday), at least one, no duplicates. Required when recurrence_type is "specific_weekdays"; ignored otherwise.'),
            'times_per_week' => $schema->integer()
                ->description('Times per week, between 1 and 7. Required when recurrence_type is "times_per_week"; ignored otherwise.'),
            'planned_time' => $schema->string()
                ->description('Optional planned time of day, "H:i" (e.g. "07:30").'),
            'identity_statement' => $schema->string()
                ->description('Optional identity statement ("Soy alguien que…"). Empty: the habit inherits its objective\'s.'),
            'objective_key' => $schema->string()
                ->description('Optional key of the objective the habit hangs from (e.g. "SALUD"). Informational link: the objective\'s lifecycle never changes the habit.'),
            'plan_id' => $schema->integer()
                ->description('Optional id of a plan of that objective.'),
            'level_ladder' => $schema->array()
                ->items($schema->object([
                    'label' => $schema->string()->required(),
                    'target' => $schema->integer(),
                    'two_minute_version' => $schema->string()->required(),
                ]))
                ->description('Optional ordered level ladder (e.g. 10 min → 30 min → 45 min), each with a label, optional target and its own 2-minute version.'),
            'level' => $schema->integer()
                ->description('Current level (1-based) when a ladder is given. Defaults to 1.'),
        ];
    }

    /**
     * The payload for the web Form Request, or an error response when the
     * objective key is not one of the caller's objectives.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|Response
     */
    protected function habitPayloadFromInput(User $user, array $input): array|Response
    {
        $key = $input['objective_key'] ?? null;
        unset($input['objective_key'], $input['objective_id']);

        if ($key === null || $key === '') {
            return [...$input, 'objective_id' => null];
        }

        $objective = $user->objectives()->where('key', strtoupper((string) $key))->first();

        if ($objective === null) {
            return Response::error("Objective not found: {$key}");
        }

        return [...$input, 'objective_id' => $objective->id];
    }
}
