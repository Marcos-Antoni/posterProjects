import { Link, usePage } from '@inertiajs/react';
import { FolderKanban } from 'lucide-react';
import { motion } from 'motion/react';

const listVariants = {
    hidden: {},
    visible: { transition: { staggerChildren: 0.04 } },
};

const itemVariants = {
    hidden: { opacity: 0, x: -8 },
    visible: { opacity: 1, x: 0 },
};

/**
 * Lists the authenticated user's projects (shared prop `sidebarProjects`,
 * see `HandleInertiaRequests`). Each entry links directly to its board —
 * `/projects/{key}/board` doesn't exist yet (ships in T-9), a 404 there is
 * expected until then (decision closed in Gate 2).
 */
export function SidebarProjectList() {
    const { props } = usePage();
    const projects = props.sidebarProjects;

    if (projects.length === 0) {
        return (
            <p className="px-2.5 text-sm text-sidebar-foreground/50">
                Todavía no tenés proyectos.
            </p>
        );
    }

    return (
        <motion.ul
            initial="hidden"
            animate="visible"
            variants={listVariants}
            className="flex flex-col gap-0.5"
        >
            {projects.map((project) => (
                <motion.li key={project.id} variants={itemVariants}>
                    <Link
                        href={`/projects/${project.key}/board`}
                        className="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-sm text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                    >
                        <FolderKanban className="size-4 shrink-0" />
                        <span className="truncate">{project.name}</span>
                        <span className="ml-auto shrink-0 text-xs text-sidebar-foreground/40">
                            {project.key}
                        </span>
                    </Link>
                </motion.li>
            ))}
        </motion.ul>
    );
}
