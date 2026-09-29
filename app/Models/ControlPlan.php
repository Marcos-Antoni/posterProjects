<?php

namespace App\Models;

use Database\Factories\ControlPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The Control 5-point plan of an objective or a plan (control-plan spec):
 * outcome, deadline (a UTC-6 calendar date), ONE metric, what can go wrong
 * and the contingency.
 *
 * @property int $id
 * @property string $plannable_type
 * @property int $plannable_id
 * @property string|null $outcome
 * @property Carbon|null $deadline
 * @property string|null $metric_name
 * @property string|null $metric_target
 * @property string|null $metric_current
 * @property list<string>|null $risks
 * @property string|null $contingency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['outcome', 'deadline', 'metric_name', 'metric_target', 'metric_current', 'risks', 'contingency'])]
class ControlPlan extends Model
{
    /** @use HasFactory<ControlPlanFactory> */
    use HasFactory;

    /**
     * The five points, in order, with the Spanish message shown when one is
     * missing (control-plan spec: activation names the missing point).
     *
     * @var array<string, string>
     */
    public const MISSING_MESSAGES = [
        'outcome' => 'Falta el resultado: qué es verdad cuando está hecho.',
        'deadline' => 'Falta la fecha límite.',
        'metric' => 'Falta la métrica. Una sola: la que mejor te diga si vas bien.',
        'risks' => 'Falta qué puede salir mal: escribí al menos un riesgo.',
        'contingency' => 'Falta la contingencia: qué hacés si un riesgo pasa.',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'metric_target' => 'decimal:2',
            'metric_current' => 'decimal:2',
            'risks' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function plannable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The points still missing, keyed like `MISSING_MESSAGES`. Empty when the
     * 5-point plan is complete and the objective or plan may be active.
     *
     * @return array<string, string>
     */
    public function missingPoints(): array
    {
        $missing = [];

        if (blank($this->outcome)) {
            $missing['outcome'] = self::MISSING_MESSAGES['outcome'];
        }

        if ($this->deadline === null) {
            $missing['deadline'] = self::MISSING_MESSAGES['deadline'];
        }

        if (blank($this->metric_name) || $this->metric_target === null) {
            $missing['metric'] = self::MISSING_MESSAGES['metric'];
        }

        if (collect($this->risks ?? [])->filter(fn ($risk): bool => filled($risk))->isEmpty()) {
            $missing['risks'] = self::MISSING_MESSAGES['risks'];
        }

        if (blank($this->contingency)) {
            $missing['contingency'] = self::MISSING_MESSAGES['contingency'];
        }

        return $missing;
    }

    public function isComplete(): bool
    {
        return $this->missingPoints() === [];
    }

    /**
     * Progress of the single metric as a 0–100 share of the target, for the
     * neutral bar (never a failure color).
     */
    public function metricProgressPercent(): ?int
    {
        if ($this->metric_target === null || (float) $this->metric_target <= 0.0) {
            return null;
        }

        $current = (float) ($this->metric_current ?? 0);

        return (int) max(0, min(100, round($current / (float) $this->metric_target * 100)));
    }
}
