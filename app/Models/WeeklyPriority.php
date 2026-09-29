<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\WeeklyPriorityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * The owner's priority for one ISO week (UTC-6): one main priority (an
 * objective or a plan) and at most two maintenance standards (an objective
 * or a habit kept at a minimum level). Drives the Now suggestion through
 * `WeeklyMainPriority` (reviews spec, design D6).
 *
 * @property int $id
 * @property int $user_id
 * @property int $iso_year
 * @property int $iso_week
 * @property string $main_type
 * @property int $main_id
 * @property list<array{type: string, id: int}>|null $maintenance
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'iso_year', 'iso_week', 'main_type', 'main_id', 'maintenance'])]
class WeeklyPriority extends Model
{
    /** @use HasFactory<WeeklyPriorityFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'maintenance' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The main priority itself: an `Objective` or a `Plan`, through the morph
     * aliases registered in AppServiceProvider.
     *
     * @return MorphTo<Model, $this>
     */
    public function main(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The maintenance standards' models (`Objective` or `Habit`), silently
     * dropping any that no longer resolve (e.g. retired since being set).
     *
     * @return list<Model>
     */
    public function maintenanceModels(): array
    {
        $models = [];

        foreach ($this->maintenance ?? [] as $entry) {
            $class = Model::getActualClassNameForMorph($entry['type']);

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $model = $class::query()->find($entry['id']);

            if ($model !== null) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * The current ISO week (UTC-6), as `iso_year`/`iso_week` are stored.
     *
     * @return array{year: int, week: int}
     */
    public static function currentWeek(): array
    {
        return self::weekOf(now());
    }

    /**
     * The ISO week right after this one (UTC-6): the weekly review sets the
     * priority for the week that is about to start.
     *
     * @return array{year: int, week: int}
     */
    public static function nextWeek(): array
    {
        return self::weekOf(now()->addWeek());
    }

    /**
     * @return array{year: int, week: int}
     */
    public static function weekOf(CarbonInterface $date): array
    {
        $local = $date->clone()->setTimezone(Config::string('habits.timezone'));

        return ['year' => (int) $local->format('o'), 'week' => (int) $local->format('W')];
    }
}
