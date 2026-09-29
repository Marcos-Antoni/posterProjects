import type { ReactNode } from 'react';

import { CumbreGlyph, MojonGlyph } from '@/components/ui/glyphs';

/**
 * The icons of the retirement screens (mockups 22 and 23), Lucide geometry at
 * 1.75px. Decorative: each one sits next to visible text.
 */
function Icon({ size = 16, children }: { size?: number; children: ReactNode }) {
    return (
        <svg
            className="ic"
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {children}
        </svg>
    );
}

export type RetirableKind =
    'task' | 'milestone' | 'plan' | 'habit' | 'objective' | 'capture';

export type RetirementDecision = 'move' | 'split' | 'archive_as_is';

export function ArchiveIcon({ size = 16 }: { size?: number }) {
    return (
        <Icon size={size}>
            <rect x="2" y="3" width="20" height="5" rx="1" />
            <path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8" />
            <path d="M10 12h4" />
        </Icon>
    );
}

export function MoveIcon({ size = 16 }: { size?: number }) {
    return (
        <Icon size={size}>
            <path d="m16 3 4 4-4 4" />
            <path d="M20 7H4" />
            <path d="m8 21-4-4 4-4" />
            <path d="M4 17h16" />
        </Icon>
    );
}

export function SplitIcon({ size = 16 }: { size?: number }) {
    return (
        <Icon size={size}>
            <path d="M16 3h5v5" />
            <path d="M8 3H3v5" />
            <path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3" />
            <path d="m15 9 6-6" />
        </Icon>
    );
}

export function RestoreIcon({ size = 16 }: { size?: number }) {
    return (
        <Icon size={size}>
            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
            <path d="M3 3v5h5" />
        </Icon>
    );
}

export function CloseIcon() {
    return (
        <Icon size={20}>
            <path d="M18 6 6 18" />
            <path d="m6 6 12 12" />
        </Icon>
    );
}

export function CheckIcon() {
    return (
        <Icon size={14}>
            <path d="M20 6 9 17l-5-5" />
        </Icon>
    );
}

export function InfoIcon() {
    return (
        <Icon size={14}>
            <circle cx="12" cy="12" r="10" />
            <path d="M12 16v-4" />
            <path d="M12 8h.01" />
        </Icon>
    );
}

export function AddIcon() {
    return (
        <Icon>
            <path d="M5 12h14" />
            <path d="M12 5v14" />
        </Icon>
    );
}

export function ArrowIcon() {
    return (
        <Icon size={14}>
            <path d="M5 12h14" />
            <path d="m12 5 7 7-7 7" />
        </Icon>
    );
}

export function DecisionIcon({ decision }: { decision: RetirementDecision }) {
    if (decision === 'move') {
        return <MoveIcon />;
    }

    if (decision === 'split') {
        return <SplitIcon />;
    }

    return <ArchiveIcon />;
}

/** The kind glyph of a Retired view row (mockup 22). */
export function KindGlyph({ kind }: { kind: RetirableKind }) {
    switch (kind) {
        case 'task':
            return (
                <Icon>
                    <rect x="4" y="7" width="16" height="10" rx="1.5" />
                </Icon>
            );
        case 'milestone':
            return <MojonGlyph size={16} className="ic" />;
        case 'objective':
            return <CumbreGlyph size={16} className="ic" />;
        case 'plan':
            return (
                <Icon>
                    <path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z" />
                    <path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65" />
                    <path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65" />
                </Icon>
            );
        case 'habit':
            return (
                <Icon>
                    <path d="m17 2 4 4-4 4" />
                    <path d="M3 11v-1a4 4 0 0 1 4-4h14" />
                    <path d="m7 22-4-4 4-4" />
                    <path d="M21 13v1a4 4 0 0 1-4 4H3" />
                </Icon>
            );
        case 'capture':
            return (
                <Icon>
                    <polyline points="22 12 16 12 14 15 10 15 8 12 2 12" />
                    <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
                </Icon>
            );
    }
}
