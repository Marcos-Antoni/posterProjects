/**
 * The few inline icons the mockups draw (Lucide geometry at 1.75px, styled
 * by `.mos svg.i`). Decorative: every one sits next to visible text or an
 * `aria-label`.
 */
export function PlusIcon() {
    return (
        <svg className="i s16" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 5v14M5 12h14" />
        </svg>
    );
}

export function PencilIcon() {
    return (
        <svg className="i s16" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M17 3a2.8 2.8 0 0 1 4 4L7.5 20.5 2 22l1.5-5.5Z" />
        </svg>
    );
}

export function AttentionIcon() {
    return (
        <svg className="i s16" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 8v5M12 16.5v.01" />
        </svg>
    );
}

export function ChevronUpIcon() {
    return (
        <svg className="i s16" viewBox="0 0 24 24" aria-hidden="true">
            <path d="m18 15-6-6-6 6" />
        </svg>
    );
}

export function ChevronDownIcon() {
    return (
        <svg className="i s16" viewBox="0 0 24 24" aria-hidden="true">
            <path d="m6 9 6 6 6-6" />
        </svg>
    );
}

export function MoreIcon() {
    return (
        <svg className="i s16" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="5" cy="12" r="1" />
            <circle cx="12" cy="12" r="1" />
            <circle cx="19" cy="12" r="1" />
        </svg>
    );
}

export function LockIcon() {
    return (
        <svg className="i" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="4" y="11" width="16" height="10" rx="2" />
            <path d="M8 11V7a4 4 0 0 1 8 0v4" />
        </svg>
    );
}

export function SearchIcon() {
    return (
        <svg className="i s16" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
        </svg>
    );
}

export function SettingsIcon() {
    return (
        <svg className="i" viewBox="0 0 24 24" aria-hidden="true">
            <line x1="21" x2="14" y1="4" y2="4" />
            <line x1="10" x2="3" y1="4" y2="4" />
            <line x1="21" x2="12" y1="12" y2="12" />
            <line x1="8" x2="3" y1="12" y2="12" />
            <line x1="21" x2="16" y1="20" y2="20" />
            <line x1="12" x2="3" y1="20" y2="20" />
            <line x1="14" x2="14" y1="2" y2="6" />
            <line x1="8" x2="8" y1="10" y2="14" />
            <line x1="16" x2="16" y1="18" y2="22" />
        </svg>
    );
}
