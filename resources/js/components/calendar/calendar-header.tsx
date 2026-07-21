import { ChevronLeft, ChevronRight } from 'lucide-react';

import { Button } from '@/components/ui/button';

type CalendarHeaderProps = {
    monthLabel: string;
    onPrevious: () => void;
    onNext: () => void;
    onToday: () => void;
};

/**
 * "◂ Hoy ▸" navigation plus the current month's name (in Spanish). Every
 * action revisits `CalendarController::index` with a different `?month=`
 * (or none, for "Hoy") — the page always renders the server's answer, it
 * never computes the issue list itself.
 */
export function CalendarHeader({
    monthLabel,
    onPrevious,
    onNext,
    onToday,
}: CalendarHeaderProps) {
    return (
        <div className="flex flex-wrap items-center gap-3">
            <div className="flex items-center gap-1">
                <Button
                    type="button"
                    variant="outline"
                    size="icon-sm"
                    onClick={onPrevious}
                    aria-label="Mes anterior"
                >
                    <ChevronLeft />
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onToday}
                >
                    Hoy
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon-sm"
                    onClick={onNext}
                    aria-label="Mes siguiente"
                >
                    <ChevronRight />
                </Button>
            </div>

            <h1 className="font-heading text-2xl font-medium capitalize">
                {monthLabel}
            </h1>
        </div>
    );
}
