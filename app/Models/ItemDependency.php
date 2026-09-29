<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "Completing the prerequisite unlocks the dependent" (unlock-graph spec).
 * Edges may cross objectives; the graph is kept acyclic by `AddDependency`.
 *
 * @property int $id
 * @property int $prerequisite_id
 * @property int $dependent_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['prerequisite_id', 'dependent_id'])]
class ItemDependency extends Model
{
    /**
     * @return BelongsTo<Item, $this>
     */
    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'prerequisite_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function dependent(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'dependent_id');
    }
}
