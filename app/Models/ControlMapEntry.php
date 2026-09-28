<?php

namespace App\Models;

use App\Enums\ControlZone;
use Database\Factories\ControlMapEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One short entry of a control map, in one of the three zones.
 *
 * @property int $id
 * @property string $plannable_type
 * @property int $plannable_id
 * @property ControlZone $zone
 * @property string $text
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['zone', 'text', 'position'])]
class ControlMapEntry extends Model
{
    /** @use HasFactory<ControlMapEntryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'zone' => ControlZone::class,
        ];
    }

    /**
     * The objective or plan this entry belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function plannable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The objective that owns this entry, directly or through its plan.
     */
    public function owningObjective(): Objective
    {
        $plannable = $this->plannable;

        return match (true) {
            $plannable instanceof Plan => $plannable->objective,
            $plannable instanceof Objective => $plannable,
            default => throw new LogicException("Control map entry {$this->id} has no objective or plan."),
        };
    }
}
