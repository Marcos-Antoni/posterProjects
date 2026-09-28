<?php

namespace App\Http\Controllers;

use App\Http\Resources\UnlockGraph;
use App\Models\Objective;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The unlock graphs (unlock-graph spec, screens 8 and 9): the minimal
 * vertical track of one objective and the global map of every active
 * objective. Read-only pages: adding or removing a dependency and checking
 * an item go through the existing item routes, which redirect back here.
 */
class UnlockGraphController extends Controller
{
    public function global(Request $request): Response
    {
        $graph = UnlockGraph::global($request->user());

        // Collapsed lines ("?collapsed=KEY,KEY"): each becomes one node,
        // laid out and routed by the server like any other.
        $collapsed = array_values(array_intersect(
            array_column($graph['objectives'], 'key'),
            explode(',', (string) $request->query('collapsed', '')),
        ));
        $track = UnlockGraph::globalTrack($graph, $collapsed);

        return Inertia::render('graphs/global', [
            'graph' => $graph,
            'layout' => $track['layout'],
            'goals' => $track['goals'],
            'routes' => $track['routes'],
            'hidden' => $track['hidden'],
            'collapsed' => $collapsed,
            // "Esta línea": the Now task's objective, else the first one.
            'lineKey' => $graph['now'] !== null && in_array($graph['now']['objective_key'], array_column($graph['objectives'], 'key'), true)
                ? $graph['now']['objective_key']
                : ($graph['objectives'][0]['key'] ?? null),
        ]);
    }

    public function objective(Objective $objective): Response
    {
        $graph = UnlockGraph::forObjective($objective);

        $track = UnlockGraph::objectiveTrack($graph);

        return Inertia::render('graphs/objective', [
            'graph' => $graph,
            'layout' => $track['layout'],
            'goals' => $track['goals'],
            'routes' => $track['routes'],
            'hidden' => $track['hidden'],
        ]);
    }
}
