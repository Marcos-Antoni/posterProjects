import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactElement } from 'react';

import { toast } from '@/components/ui/toast';
import AppLayout from '@/layouts/app-layout';
import { accept, reject } from '@/routes/ai/proposals';

type ProposalStatus = 'pending' | 'accepted' | 'rejected';

type Proposal = {
    id: number;
    kind: string;
    summary: string;
    status: ProposalStatus;
    status_label: string;
    source: string;
    created_at: string | null;
    decided_at: string | null;
};

type AuditEntry = {
    id: number;
    source: string;
    tier: string;
    operation: string;
    target_type: string | null;
    target_id: number | null;
    created_at: string | null;
};

type Props = {
    pending: Proposal[];
    decided: Proposal[];
    audit: AuditEntry[];
};

const KIND_LABELS: Record<string, string> = {
    create_plan: 'Crear plan',
    retire: 'Retirar',
};

function when(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('es') : '';
}

/**
 * Screen 24 (mockup visual/screens/24-ai-proposals.html), trimmed to a
 * single simple list (no grants, no stale-target diff, no tree
 * negotiation): pending proposals Marco accepts or rejects exactly as
 * stored, a short decided history, and the last ~20 AI audit entries
 * (ai-operations spec).
 */
export default function AiProposalsIndex({ pending, decided, audit }: Props) {
    const [processingId, setProcessingId] = useState<number | null>(null);

    const decide = (proposal: Proposal, action: 'accept' | 'reject') => {
        setProcessingId(proposal.id);
        const url = action === 'accept' ? accept(proposal.id).url : reject(proposal.id).url;

        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast(
                        action === 'accept'
                            ? 'Propuesta aceptada y aplicada.'
                            : 'Propuesta rechazada.',
                    ),
                onError: (errors) => {
                    const first = Object.values(errors)[0];
                    toast(typeof first === 'string' ? first : 'No se pudo decidir la propuesta.');
                },
                onFinish: () => setProcessingId(null),
            },
        );
    };

    return (
        <div className="mos-s16">
            <Head title="Propuestas de la IA" />
            <div className="head">
                <div>
                    <h1 className="h1">Propuestas de la IA</h1>
                    <p className="lede">
                        Lo que la IA quiere cambiar y no puede hacer sola.
                        Nada de esto se aplicó: se aplica exactamente como
                        está cuando lo aceptás.
                    </p>
                </div>
            </div>

            {pending.length === 0 ? (
                <div className="card" style={{ padding: 24 }}>
                    <h3 style={{ margin: '0 0 4px' }}>
                        No hay nada esperando tu decisión.
                    </h3>
                    <p className="meta">
                        Cuando la IA quiera crear un plan o retirar algo, la
                        propuesta aparece acá con su resumen. Lo menor (marcar
                        hecho, registrar un hábito, capturar) lo hace sola y
                        queda en la actividad de más abajo.
                    </p>
                </div>
            ) : (
                <ol
                    className="card"
                    style={{ padding: 8, listStyle: 'none' }}
                >
                    {pending.map((proposal) => (
                        <li
                            key={proposal.id}
                            id={`proposal-${proposal.id}`}
                            style={{ padding: 16 }}
                        >
                            <p className="meta">
                                <span className="pill on">
                                    {KIND_LABELS[proposal.kind] ??
                                        proposal.kind}
                                </span>
                                <span>{proposal.source}</span>
                                <span>{when(proposal.created_at)}</span>
                            </p>
                            <p
                                className="txt"
                                style={{ margin: '8px 0', fontWeight: 600 }}
                            >
                                {proposal.summary}
                            </p>
                            <div
                                style={{
                                    display: 'flex',
                                    gap: 8,
                                    flexWrap: 'wrap',
                                }}
                            >
                                <button
                                    className="btn btn-primary btn-sm"
                                    type="button"
                                    disabled={processingId === proposal.id}
                                    onClick={() => decide(proposal, 'accept')}
                                >
                                    Aceptar
                                </button>
                                <button
                                    className="btn btn-outline btn-sm"
                                    type="button"
                                    disabled={processingId === proposal.id}
                                    onClick={() => decide(proposal, 'reject')}
                                >
                                    Rechazar
                                </button>
                            </div>
                        </li>
                    ))}
                </ol>
            )}

            {decided.length > 0 && (
                <details open style={{ marginTop: 32 }}>
                    <summary>Decididas ({decided.length})</summary>
                    <ul style={{ listStyle: 'none', padding: 0 }}>
                        {decided.map((proposal) => (
                            <li key={proposal.id} className="meta">
                                <span
                                    className={
                                        proposal.status === 'accepted'
                                            ? 'pill ok'
                                            : 'pill'
                                    }
                                >
                                    {proposal.status_label}
                                </span>{' '}
                                <b>{proposal.summary}</b>{' '}
                                <span>{when(proposal.decided_at)}</span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            {audit.length > 0 && (
                <section style={{ marginTop: 32 }}>
                    <h2 style={{ margin: '0 0 12px' }}>
                        Actividad reciente de la IA
                    </h2>
                    <ul
                        className="card"
                        style={{ padding: 8, listStyle: 'none' }}
                    >
                        {audit.map((entry) => (
                            <li
                                key={entry.id}
                                className="meta"
                                style={{ padding: '8px 8px' }}
                            >
                                <span className="pill">{entry.tier}</span>
                                <span>{entry.operation}</span>
                                <span>{entry.source}</span>
                                {entry.target_type && (
                                    <span>
                                        {entry.target_type} #{entry.target_id}
                                    </span>
                                )}
                                <span>{when(entry.created_at)}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}

AiProposalsIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
