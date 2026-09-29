<?php

namespace App\Models;

use App\Enums\ReviewKind;
use App\Models\Scopes\NotRetired;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One review, kept forever (reviews spec: "Reviews Are Kept As History"): a
 * weekly priority review (`answers`: what worked, what got in the way) or an
 * objective's learning review at close time (`answers`: what I learned, what
 * I would repeat, what I would change, plus the habit keep/retire decisions).
 * A milestone summit's evidence lives in `milestone_evidence`, not here.
 *
 * @property int $id
 * @property int $user_id
 * @property ReviewKind $kind
 * @property int|null $objective_id
 * @property array<string, mixed> $answers
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'kind', 'objective_id', 'answers'])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReviewKind::class,
            'answers' => 'array',
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
     * The objective a learning review closed. Reaches a since-retired
     * objective too (same pattern as `Retirement::objective()`): a review is
     * history and stays readable.
     *
     * @return BelongsTo<Objective, $this>
     */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class)->withoutGlobalScope(NotRetired::class);
    }
}
