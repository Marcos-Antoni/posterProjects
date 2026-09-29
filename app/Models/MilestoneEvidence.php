<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The evidence recorded when a milestone is completed (issues spec).
 *
 * @property int $id
 * @property int $item_id
 * @property string $text
 * @property string|null $link
 * @property string|null $image_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('milestone_evidence')]
#[Fillable(['item_id', 'text', 'link', 'image_path'])]
class MilestoneEvidence extends Model
{
    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
