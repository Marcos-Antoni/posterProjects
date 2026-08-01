<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileTokenQrController extends Controller
{
    /**
     * Mint a QR login pass for the authenticated owner, or report that the
     * owner's most recent pass was already consumed.
     *
     * `lockForUpdate()` on the latest row closes the re-mint/consume race
     * (design.md Decision 3): if a phone redeemed the pass between the
     * owner's last poll and this click, the row is already `consumed_at`
     * non-null. By default this method then writes nothing and reports
     * `state: consumed` — a blind delete-then-insert would destroy that row
     * and, with it, the theft-detection signal the owner is here to see.
     *
     * The owner can move past that notice deliberately by sending
     * `acknowledge_consumed`. The card only ever sets it from the explicit
     * "Regenerar código QR" button rendered inside the consumed state —
     * never from the status poll, an automatic re-mint, or a page load —
     * so a poll, a prefetch, or a refresh can never trigger it by accident.
     * With the flag set, this method proceeds to mint a fresh pass even
     * over a consumed latest row.
     *
     * Either way, the delete before minting is scoped to
     * `whereNull('consumed_at')`, so every consumed row (this owner's audit
     * trail) is retained indefinitely. An acknowledged mint does not erase
     * the record of the earlier consumption; it only stops that row from
     * being the *latest* one, so a subsequent `status()` or plain `store()`
     * call reports the new live pass instead of resurfacing the old notice.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $acknowledgeConsumed = $request->boolean('acknowledge_consumed');

        return DB::transaction(function () use ($user, $acknowledgeConsumed): JsonResponse {
            $latest = $user->qrLoginPasses()->latest('id')->lockForUpdate()->first();

            if ($latest !== null && $latest->consumed_at !== null && ! $acknowledgeConsumed) {
                return response()->json([
                    'state' => 'consumed',
                    'consumed_at' => $latest->consumed_at->toISOString(),
                    'consumed_ip' => $latest->consumed_ip,
                ]);
            }

            $user->qrLoginPasses()->whereNull('consumed_at')->delete();

            $plain = 'pposter_qr_v1:'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $expiresAt = now()->addSeconds(60);

            $user->qrLoginPasses()->create([
                'token_hash' => hash('sha256', $plain),
                'expires_at' => $expiresAt,
            ]);

            // The plaintext is returned exactly once, in this response
            // only — see qr-login-card.tsx for how the browser must never
            // persist or re-fetch it (design.md "Plaintext handling").
            return response()->json([
                'state' => 'live',
                'payload' => $plain,
                'expires_at' => $expiresAt->toISOString(),
            ]);
        });
    }

    /**
     * Report the owner's latest pass state without ever exposing the
     * plaintext — the asymmetry with `store()` above *is* the security
     * property (design.md "Interfaces / Contracts").
     */
    public function status(Request $request): JsonResponse
    {
        $latest = $request->user()->qrLoginPasses()->latest('id')->first();

        if ($latest === null) {
            return response()->json(['state' => 'none']);
        }

        if ($latest->consumed_at !== null) {
            return response()->json([
                'state' => 'consumed',
                'consumed_at' => $latest->consumed_at->toISOString(),
                'consumed_ip' => $latest->consumed_ip,
            ]);
        }

        if ($latest->expires_at->isPast()) {
            return response()->json(['state' => 'expired']);
        }

        return response()->json([
            'state' => 'live',
            'expires_at' => $latest->expires_at->toISOString(),
        ]);
    }
}
