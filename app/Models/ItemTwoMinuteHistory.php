<?php

namespace App\Models;

use App\Enums\TwoMinuteSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A replaced 2-minute version: `text` is the version that was replaced,
 * `source` who replaced it and `replaced_at` when (the "Cómo se fue
 * achicando" history of the item screen).
 *
 * @property int $id
 * @property int $item_id
 * @property string $text
 * @property TwoMinuteSource $source
 * @property Carbon $replaced_at
 */
#[Table('item_two_minute_history')]
#[Fillable(['item_id', 'text', 'source', 'replaced_at'])]
class ItemTwoMinuteHistory extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => TwoMinuteSource::class,
            'replaced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
