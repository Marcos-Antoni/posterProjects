import { Link } from '@inertiajs/react';
import { motion } from 'motion/react';
import type { PropsWithChildren } from 'react';

import { home } from '@/routes';

type AuthLayoutProps = PropsWithChildren<{
    title: string;
    description?: string;
}>;

/**
 * Shared shell for guest-only pages (login, etc.): centers a Motion-animated
 * card on the screen with the app name, a title/description pair, and the
 * page content.
 */
export default function AuthLayout({
    title,
    description,
    children,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <motion.div
                initial={{ opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4, ease: 'easeOut' }}
                className="flex w-full max-w-sm flex-col gap-6"
            >
                <Link
                    href={home()}
                    className="flex items-center justify-center gap-2 self-center font-heading text-lg font-medium"
                >
                    Jira Clone
                </Link>

                <div className="flex flex-col items-center gap-2 text-center">
                    <h1 className="font-heading text-xl font-medium">
                        {title}
                    </h1>
                    {description ? (
                        <p className="text-sm text-balance text-muted-foreground">
                            {description}
                        </p>
                    ) : null}
                </div>

                {children}
            </motion.div>
        </div>
    );
}
