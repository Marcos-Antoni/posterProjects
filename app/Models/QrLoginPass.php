<?php

namespace App\Models;

use Database\Factories\QrLoginPassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A short-lived, single-use QR login pass. Only `sha256(plaintext)` is ever
 * stored — see design.md Decision 1. `consumed_at` is nullable and doubles
 * as the liveness flag *and* the audit record: `NULL` means the pass is
 * still redeemable, a timestamp means it was claimed (and by which IP).
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $consumed_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'token_hash', 'expires_at', 'consumed_at', 'consumed_ip'])]
class QrLoginPass extends Model
{
    /** @use HasFactory<QrLoginPassFactory> */
    use HasFactory;

    /**
     * `immutable_datetime`, not `datetime` — `AppServiceProvider::configureDefaults()`
     * sets `Date::use(CarbonImmutable::class)` app-wide.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
