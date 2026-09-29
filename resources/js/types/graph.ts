import type { ItemKind, ItemState } from '@/types/models';

/**
 * Mirrors `App\Http\Resources\UnlockGraph` (the shared read model of the
 * web graphs and the MCP `objective-graph` / `global-graph` tools).
 */
export type GraphNode = {
    key: string;
    number: number;
    kind: ItemKind;
    title: string;
    state: ItemState;
    plan_id: number;
    two_minute_version: string;
    completed_at: string | null;
};

export type GraphPlan = { id: number; title: string };

export type GraphRetired = {
    key: string;
    title: string;
    plan_id: number;
    reason: string | null;
};

export type GraphStub = {
    key: string;
    kind: ItemKind;
    title: string;
    state: ItemState;
    objective_key: string;
    objective_title: string;
};

export type GraphNow = {
    key: string;
    title: string;
    objective_key: string;
    objective_title: string;
};

export type ObjectiveGraph = {
    objective: {
        key: string;
        title: string;
        state: string;
        is_writable: boolean;
    };
    goal: {
        title: string;
        deadline: string | null;
        metric: {
            name: string | null;
            current: number | null;
            target: number | null;
        } | null;
    };
    plans: GraphPlan[];
    nodes: GraphNode[];
    edges: { from: string; to: string; external: boolean }[];
    stubs: GraphStub[];
    retired: GraphRetired[];
    now: GraphNow | null;
    next_milestone: { key: string; title: string; remaining: number } | null;
};

export type GlobalCluster = {
    key: string;
    title: string;
    line: number;
    progress: { done: number; total: number; available: number };
    plans: GraphPlan[];
    nodes: GraphNode[];
    retired: GraphRetired[];
};

export type GlobalGraph = {
    objectives: GlobalCluster[];
    edges: { from: string; to: string; cross: boolean }[];
    now: GraphNow | null;
    now_unlocks: string[];
};

/** Row (0 = top; stubs sit on half rows) and lane (0 = main track). */
export type TrackLayout = Record<string, { row: number; lane: number }>;
