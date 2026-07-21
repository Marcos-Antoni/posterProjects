import { Head, router } from '@inertiajs/react';
import { addMonths, format } from 'date-fns';
import { es } from 'date-fns/locale';
import { AnimatePresence, motion } from 'motion/react';
import type { ReactElement } from 'react';
import { useState } from 'react';

import { index as calendarIndex } from '@/actions/App/Http/Controllers/CalendarController';
import { CalendarGrid } from '@/components/calendar/calendar-grid';
import { CalendarHeader } from '@/components/calendar/calendar-header';
import AppLayout from '@/layouts/app-layout';
import type { CalendarIssue } from '@/types/calendar';

type CalendarPageProps = {
    /** "YYYY-MM", always the first day of the rendered month. */
    month: string;
    issues: CalendarIssue[];
};

/**
 * Parses a "YYYY-MM" string into the local first-of-month `Date` — never
 * via `new Date(string)`, which parses a date-only string as UTC midnight
 * and can shift the displayed month backward in timezones behind UTC (same
 * pitfall documented by `edit-form.tsx`'s `parseIsoDate`).
 */
function parseMonthKey(monthKey: string): Date {
    const [year, month] = monthKey.split('-').map(Number);

    return new Date(year, month - 1, 1);
}

function formatMonthKey(date: Date): string {
    return format(date, 'yyyy-MM');
}

/**
 * Global, cross-project calendar (`CalendarController::index`): every
 * issue with a due date this month, across every project the authenticated
 * user is a member of. Navigation (prev/next/"Hoy") always revisits the
 * backend with a `?month=` — the grid never computes a month's issues
 * itself, it only renders whatever `issues` it was given.
 */
export default function Calendar({ month, issues }: CalendarPageProps) {
    const monthStart = parseMonthKey(month);
    // Which way the grid slides in Motion's transition — set right before
    // navigating, read on the next render once the new `month` prop lands.
    const [direction, setDirection] = useState<1 | -1>(1);

    function navigateToMonth(nextMonthKey: string, nextDirection: 1 | -1) {
        setDirection(nextDirection);
        router.get(
            calendarIndex.url(),
            { month: nextMonthKey },
            { preserveScroll: true, preserveState: true },
        );
    }

    function goToPrevious() {
        navigateToMonth(formatMonthKey(addMonths(monthStart, -1)), -1);
    }

    function goToNext() {
        navigateToMonth(formatMonthKey(addMonths(monthStart, 1)), 1);
    }

    function goToToday() {
        const todayKey = formatMonthKey(new Date());

        navigateToMonth(todayKey, todayKey >= month ? 1 : -1);
    }

    const monthLabel = format(monthStart, 'LLLL yyyy', { locale: es });

    return (
        <>
            <Head title="Calendario" />

            <div className="flex flex-col gap-6">
                <CalendarHeader
                    monthLabel={monthLabel}
                    onPrevious={goToPrevious}
                    onNext={goToNext}
                    onToday={goToToday}
                />

                <AnimatePresence mode="wait" initial={false}>
                    <motion.div
                        key={month}
                        initial={{ opacity: 0, x: direction * 16 }}
                        animate={{ opacity: 1, x: 0 }}
                        exit={{ opacity: 0, x: direction * -16 }}
                        transition={{ duration: 0.2, ease: 'easeOut' }}
                    >
                        <CalendarGrid monthStart={monthStart} issues={issues} />
                    </motion.div>
                </AnimatePresence>
            </div>
        </>
    );
}

Calendar.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
