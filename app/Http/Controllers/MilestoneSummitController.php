<?php

namespace App\Http\Controllers;

use App\Enums\ItemKind;
use App\Enums\ItemState;
use App\Http\Resources\ItemDetails;
use App\Models\Item;
use App\Models\Objective;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The milestone summit (screen 12): the completion moment for a milestone,
 * with its evidence (text/link/optional image), what it opened and the path
 * of completed items that led to it (reviews spec "A Milestone Summit
 * Records Evidence"). The evidence form posts to the existing
 * `objectives.items.check` route (same as "Marcar hecho" everywhere else);
 * this controller only renders the page, before and after that happens.
 */
class MilestoneSummitController extends Controller
{
    public function show(Objective $objective, string $item): Response
    {
        $resolved = Item::resolveByKey($objective, $item);

        abort_if($resolved === null || $resolved->kind !== ItemKind::Milestone, 404);

        $resolved = ItemDetails::load($resolved);
        $isDone = $resolved->completed_at !== null;

        return Inertia::render('items/summit', [
            'objective' => ['key' => $objective->key, 'title' => $objective->title],
            'item' => [
                ...ItemDetails::present($resolved),
                'number' => $resolved->number,
            ],
            'opened' => $isDone ? ItemDetails::neighbours($resolved->dependents->whereNull('retired_at')->filter(fn (Item $dependent): bool => $dependent->state === ItemState::Available)) : [],
            'path' => $isDone ? $this->path($resolved) : [],
        ]);
    }

    /**
     * Every non-retired, completed item upstream of this milestone (direct
     * or transitive prerequisite), oldest first: "the path taken" the
     * summit shows.
     *
     * @return list<array{title: string, completed_at: string}>
     */
    private function path(Item $milestone): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE upstream(id, depth) AS (
                SELECT prerequisite_id, 1 FROM item_dependencies WHERE dependent_id = ?
                UNION
                SELECT edges.prerequisite_id, upstream.depth + 1
                FROM item_dependencies AS edges
                INNER JOIN upstream ON edges.dependent_id = upstream.id
                WHERE upstream.depth < 64
            )
            SELECT DISTINCT items.title, items.completed_at
            FROM upstream
            INNER JOIN items ON items.id = upstream.id
            WHERE items.completed_at IS NOT NULL AND items.retired_at IS NULL
            ORDER BY items.completed_at ASC
            SQL, [$milestone->id]);

        return array_values(array_map(function (object $row): array {
            $attributes = get_object_vars($row);

            return [
                'title' => (string) ($attributes['title'] ?? ''),
                'completed_at' => (string) ($attributes['completed_at'] ?? ''),
            ];
        }, $rows));
    }
}
