<?php

namespace App\Http\Controllers;

use App\Actions\Proposals\AcceptProposal;
use App\Actions\Proposals\RejectProposal;
use App\Enums\ProposalStatus;
use App\Models\AiAuditLog;
use App\Models\AiProposal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Propuestas" (screen 24, trimmed Phase 8 slice): pending AI proposals
 * Marco accepts or rejects, a short history of decided ones, and the last
 * ~20 AI audit entries (ai-operations spec "Every AI Change Is Audited").
 */
class AiProposalController extends Controller
{
    public function index(Request $request): Response
    {
        $owner = $request->user();

        $pending = AiProposal::query()
            ->where('user_id', $owner->id)
            ->where('status', ProposalStatus::Pending)
            ->orderByDesc('created_at')
            ->get();

        $decided = AiProposal::query()
            ->where('user_id', $owner->id)
            ->whereIn('status', [ProposalStatus::Accepted, ProposalStatus::Rejected])
            ->orderByDesc('decided_at')
            ->limit(10)
            ->get();

        $audit = AiAuditLog::query()
            ->where('user_id', $owner->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return Inertia::render('ai/proposals', [
            'pending' => $pending->map(fn (AiProposal $proposal): array => $this->present($proposal))->all(),
            'decided' => $decided->map(fn (AiProposal $proposal): array => $this->present($proposal))->all(),
            'audit' => $audit->map(fn (AiAuditLog $entry): array => [
                'id' => $entry->id,
                'source' => $entry->source,
                'tier' => $entry->tier,
                'operation' => $entry->operation,
                'target_type' => $entry->target_type,
                'target_id' => $entry->target_id,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Accepts a pending proposal: applies it exactly as stored. A stranger's
     * proposal or an already-decided one fails (ModelNotFoundException /
     * ValidationException), handled by Laravel's default exception
     * rendering like every other domain action in this app.
     */
    public function accept(Request $request, AiProposal $proposal, AcceptProposal $accept): RedirectResponse
    {
        $accept($request->user(), $proposal);

        return back();
    }

    public function reject(Request $request, AiProposal $proposal, RejectProposal $reject): RedirectResponse
    {
        $reject($request->user(), $proposal);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'kind' => $proposal->kind,
            'summary' => $proposal->summary,
            'payload' => $proposal->payload,
            'status' => $proposal->status->value,
            'status_label' => $proposal->status->label(),
            'source' => $proposal->source,
            'created_at' => $proposal->created_at?->toIso8601String(),
            'decided_at' => $proposal->decided_at?->toIso8601String(),
        ];
    }
}
