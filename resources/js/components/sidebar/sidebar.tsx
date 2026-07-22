import { motion } from 'motion/react';

import { SidebarContent } from '@/components/sidebar/sidebar-content';

/**
 * Persistent desktop app shell sidebar: hidden below `md`, where
 * `MobileSidebar` renders the same navigation inside a drawer instead
 * (see `app-layout.tsx`). Both share their inner content via
 * `SidebarContent`.
 */
export function Sidebar() {
    return (
        <motion.aside
            initial={{ opacity: 0, x: -12 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: 0.3, ease: 'easeOut' }}
            className="hidden w-64 shrink-0 flex-col gap-4 border-r border-sidebar-border bg-sidebar p-4 text-sidebar-foreground md:flex"
        >
            <SidebarContent />
        </motion.aside>
    );
}
