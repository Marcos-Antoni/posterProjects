<?php

namespace App\Actions\Proposals;

use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Models\AiProposal;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Stores a pending AI proposal (ai-operations spec "Major Operations Become
 * Proposals", trimmed slice): the AI's exact `kind`/`payload` and a
 * human-readable Spanish `summary`. Nothing is applied here — only
 * `ApplyProposal` (via `AcceptProposal`) ever touches the domain.
 */
class CreateProposal
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, string $kind, array $payload, string $summary, string $source = 'mcp'): AiProposal
    {
        if (ProposalKind::tryFrom($kind) === null) {
            $supported = implode(', ', array_map(fn (ProposalKind $case): string => $case->value, ProposalKind::cases()));

            throw ValidationException::withMessages(['kind' => "Tipo de propuesta no soportado: «{$kind}». Usá uno de: {$supported}."]);
        }

        $summary = trim($summary);

        if ($summary === '') {
            throw ValidationException::withMessages(['summary' => 'Falta el resumen de la propuesta: una frase en español que Marco pueda leer.']);
        }

        return AiProposal::create([
            'user_id' => $user->id,
            'kind' => $kind,
            'payload' => $payload,
            'summary' => $summary,
            'status' => ProposalStatus::Pending,
            'source' => $source,
        ]);
    }
}
