<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\IssuesMobileToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\QrLoginRequest;
use App\Models\QrLoginPass;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QrLoginController extends Controller
{
    use IssuesMobileToken;

    /**
     * Redeem a QR login pass for a `mobile` bearer token.
     *
     * DO NOT refactor this into `SELECT → check → UPDATE`. That shape looks
     * equivalent and passes every behavioural test in this file — it is
     * only unsafe under real concurrency, which Pest (single-threaded)
     * cannot exercise. Two phones racing the same pass would both read
     * `consumed_at IS NULL`, both pass the check, and both mint a token;
     * since a QR login revokes the prior `mobile` token, the second
     * issuance silently kills the session the first phone just received.
     *
     * The fix is that consumption IS the single conditional `UPDATE`
     * below. `$affected` is not a value this code checks — it is the
     * row-level lock result the *database engine* reports back, and there
     * is nothing between the read and the write for a second request to
     * race, because they are the same statement. Whichever request's
     * `UPDATE` commits first wins the row; the loser's own `WHERE
     * consumed_at IS NULL` is re-evaluated against the now-committed row
     * and matches zero rows. See `tests/Feature/ApiQrLoginTest.php`'s
     * `DB::listen` shape assertion — it is the only test that would catch
     * a regression back to the read-then-write form, because it inspects
     * statement order/count instead of behaviour. See design.md "Flow B"
     * for the full trace of why the naive form fails.
     */
    public function redeem(QrLoginRequest $request): JsonResponse
    {
        $hash = hash('sha256', $request->string('token')->value());

        $affected = DB::table('qr_login_passes')
            ->where('token_hash', $hash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update([
                'consumed_at' => now(),
                'consumed_ip' => $request->ip(),
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            $this->reject();
        }

        // Safe only because the row above is already claimed: this SELECT
        // comes strictly after the winning write, never before it.
        $pass = QrLoginPass::query()->where('token_hash', $hash)->sole();

        return $this->issueMobileToken($pass->user);
    }

    /**
     * The single failure exit. Unknown, expired, and consumed passes are
     * literally the same code path — the conditional `UPDATE` above
     * cannot distinguish them, and this deliberately doesn't try to
     * (design.md "Decision 4": a discriminating branch here would be an
     * oracle for an attacker holding a stolen QR photo).
     */
    private function reject(): never
    {
        throw ValidationException::withMessages([
            'token' => 'El código QR no es válido o expiró.',
        ]);
    }
}
