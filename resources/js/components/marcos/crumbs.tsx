import { Link } from '@inertiajs/react';
import { Fragment } from 'react';

export type Crumb = { label: string; href?: string };

/**
 * The breadcrumb trail of the mockups (`.crumbs`): links separated by a
 * chevron, the last one plain text.
 */
export function Crumbs({ items }: { items: Crumb[] }) {
    return (
        <nav className="crumbs" aria-label="Ruta">
            {items.map((item, index) => (
                <Fragment key={`${item.label}-${index}`}>
                    {index > 0 && (
                        <svg
                            className="i"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path d="m9 18 6-6-6-6" />
                        </svg>
                    )}
                    {item.href ? (
                        <Link href={item.href}>{item.label}</Link>
                    ) : (
                        <span>{item.label}</span>
                    )}
                </Fragment>
            ))}
        </nav>
    );
}
