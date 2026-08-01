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
     * non-null and this method writes nothing — a blind delete-then-insert
     * would destroy that row and, with it, the theft-detection signal the
     * owner is here to see. Only when the latest row is still unconsumed
     * does this delete the owner's unconsumed passes and mint a fresh one;
     * the delete is scoped to `whereNull('consumed_at')` so every consumed
     * row (this owner's audit trail) is retained indefinitely.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        return DB::transaction(function () use ($user): JsonResponse {
            $latest = $user->qrLoginPasses()->latest('id')->lockForUpdate()->first();

            if ($latest !== null && $latest->consumed_at !== null) {
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
