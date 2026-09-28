import type { ReactNode, SVGProps } from 'react';

/**
 * The three custom Marcos OS glyphs (§4.8), drawn in Lucide's style:
 * 24×24 grid, 1.75px stroke, round caps/joins, `currentColor`.
 *
 * - `MarcaGlyph`: la marca — a painted rectangle with a check (task done).
 * - `MojonGlyph`: el mojón — three stacked stones (milestone).
 * - `CumbreGlyph`: la cumbre — a peak with a flag (objective).
 *
 * Decorative by default (`aria-hidden`); pass `title` to make one
 * announce itself. Icons always sit next to text except in the graph
 * toolbar (§4.8).
 */
type GlyphProps = Omit<SVGProps<SVGSVGElement>, 'children'> & {
    size?: number;
    title?: string;
};

function Glyph({
    size = 20,
    title,
    strokeWidth = 1.75,
    children,
    ...props
}: GlyphProps & { children: ReactNode }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={strokeWidth}
            strokeLinecap="round"
            strokeLinejoin="round"
            role={title ? 'img' : undefined}
            aria-hidden={title ? undefined : true}
            focusable="false"
            {...props}
        >
            {title ? <title>{title}</title> : null}
            {children}
        </svg>
    );
}

export function MarcaGlyph(props: GlyphProps) {
    return (
        <Glyph {...props}>
            <rect x="3" y="6" width="18" height="12" rx="1" />
            <path d="m8 12 3 3 5-6" />
        </Glyph>
    );
}

export function MojonGlyph(props: GlyphProps) {
    return (
        <Glyph {...props}>
            <path d="M4 20h16" />
            <path d="M5.5 20c0-2.2 1.9-3.5 6.5-3.5s6.5 1.3 6.5 3.5" />
            <path d="M8 16.5c0-1.9 1.6-3 4-3s4 1.1 4 3" />
            <path d="M9.8 13.5c0-1.6 1-2.6 2.2-2.6s2.2 1 2.2 2.6" />
        </Glyph>
    );
}

export function CumbreGlyph(props: GlyphProps) {
    return (
        <Glyph {...props}>
            <path d="m2.5 20 7-11 3.5 5.2L15 11l6.5 9Z" />
            <path d="M9.5 9V3" />
            <path d="M9.5 3.3h4.5l-1.2 1.6 1.2 1.6H9.5" />
        </Glyph>
    );
}
