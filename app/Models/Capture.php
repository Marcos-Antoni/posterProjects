<?php

namespace App\Models;

use App\Enums\CaptureSource;
use App\Models\Concerns\HasRetirement;
use App\Models\Concerns\Retirable;
use Database\Factories\CaptureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A quick-captured idea (capture-inbox spec): one field, a source, a UTC
 * timestamp. It never carries priority, objective, plan or date, and it is
 * never the Now task. Triage keeps a link to what it became (`result`); the
 * capture itself stays in the untriaged list until triaged or retired.
 *
 * @property int $id
 * @property int $user_id
 * @property string $text
 * @property CaptureSource $source
 * @property Carbon|null $triaged_at
 * @property string|null $result_type
 * @property int|null $result_id
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'text', 'source', 'triaged_at', 'result_type', 'result_id', 'retired_at'])]
class Capture extends Model implements Retirable
{
    /** @use HasFactory<CaptureFactory> */
    use HasFactory;

    use HasRetirement;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => CaptureSource::class,
            'triaged_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * Retired captures carry `retired_at`, the same pattern as habits.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainRetired(Builder $query, bool $retired): void
    {
        $retired
            ? $query->whereNotNull($this->qualifyColumn('retired_at'))
            : $query->whereNull($this->qualifyColumn('retired_at'));
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What this capture became once triaged (an item, a habit or a draft
     * objective), through the morph aliases registered in AppServiceProvider.
     *
     * @return MorphTo<Model, $this>
     */
    public function result(): MorphTo
    {
        return $this->morphTo();
    }

    public function isTriaged(): bool
    {
        return $this->triaged_at !== null;
    }

    /**
     * Untriaged captures, oldest first (capture-inbox spec: "oldest first").
     *
     * @param  Builder<self>  $query
     */
    public function scopeUntriaged(Builder $query): void
    {
        $query->whereNull('triaged_at')->orderBy('created_at')->orderBy('id');
    }
}
