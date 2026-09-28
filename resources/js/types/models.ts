/**
 * Domain types mirroring the Eloquent models in `app/Models`.
 *
 * Dates are ISO 8601 strings (as serialized by Laravel), never `Date`
 * instances — Inertia sends plain JSON over the wire.
 */

/** Mirrors `App\Enums\ObjectiveState`. */
export type ObjectiveState = 'draft' | 'active' | 'closed' | 'retired';

/** Mirrors `App\Enums\PlanState`. `done` is reached automatically. */
export type PlanState = 'draft' | 'active' | 'done' | 'retired';

/** Mirrors `App\Enums\ItemKind`. */
export type ItemKind = 'task' | 'milestone';

/** Mirrors `App\Enums\ItemState` — derived, never stored. */
export type ItemState = 'locked' | 'available' | 'active' | 'done' | 'retired';

/** Mirrors `App\Enums\ControlZone`. Only `mine`/`influence` may become tasks. */
export type ControlZone = 'mine' | 'influence' | 'outside';

/** The Control 5-point plan as `ObjectiveTree::controlPlan()` presents it. */
export type ControlPlan = {
    outcome: string | null;
    /** A UTC-6 calendar date, "YYYY-MM-DD". */
    deadline: string | null;
    metric: {
        name: string | null;
        target: number | null;
        current: number | null;
        progress_percent: number | null;
    };
    risks: string[];
    contingency: string | null;
    /** Keys of the missing points (outcome, deadline, metric, risks, contingency). */
    missing: string[];
};

export type ControlMapEntry = {
    id: number;
    zone: ControlZone;
    text: string;
    can_become_task: boolean;
};

/** A prerequisite still open, as the tree shows it ("Se abre al terminar …"). */
export type WaitingOn = {
    key: string;
    kind: ItemKind;
    title: string;
    objective_key: string;
    objective_title: string;
    external: boolean;
};

/** One row of an objective's tree (`ObjectiveTree::item()`). */
export type TreeItem = {
    id: number;
    key: string;
    number: number;
    kind: ItemKind;
    title: string;
    two_minute_version: string;
    state: ItemState;
    target_date: string | null;
    completed_at: string | null;
    prerequisite_keys: string[];
    waiting_on: WaitingOn[];
};

export type TreePlan = {
    id: number;
    title: string;
    state: PlanState;
    level: number | null;
    position: number;
    progress: { done: number; total: number };
    next_milestone: { title: string; remaining: number } | null;
    retired_titles: string[];
    items: TreeItem[];
};

/** A neighbour of an item (prerequisite or unlock). */
export type ItemRef = {
    key: string;
    title: string;
    state: ItemState;
};

export type HabitType = 'yes_no' | 'quantitative';

export type RecurrenceType = 'daily' | 'specific_weekdays' | 'times_per_week';

export type Habit = {
    id: number;
    user_id: number;
    name: string;
    habit_type: HabitType;
    unit: string | null;
    daily_target: number | null;
    recurrence_type: RecurrenceType;
    /** ISO-8601 weekday numbers, 1 (Monday) to 7 (Sunday). */
    weekdays: number[] | null;
    times_per_week: number | null;
    /** Time of day "HH:MM:SS" in the feature's fixed UTC-6 zone. */
    planned_time: string | null;
    /** Set once the habit is retired (hidden; history kept; restore from Retirados). */
    retired_at: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type HabitDay = {
    id: number;
    habit_id: number;
    /** The day in the feature's fixed UTC-6 zone, "YYYY-MM-DD". */
    entry_date: string;
    accumulated_amount: number;
    /** Persisted as recorded — may be under or over 100. */
    completion_percent: number;
    completed: boolean;
    planned_delta_minutes: number | null;
    created_at: string | null;
    updated_at: string | null;
};
