<?php

namespace App\Actions\Items;

use App\Actions\Support\Actor;
use App\Actions\Support\DomainTransaction;
use App\Actions\Support\GuardsObjectives;
use App\Actions\Support\Operation;
use App\Models\Item;
use App\Models\ItemDependency;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Completing the prerequisite unlocks the dependent" (unlock-graph spec).
 * Edges may cross objectives. Self-edges, duplicates, retired items and any
 * edge that would close a cycle are refused; a cycle is found with one
 * recursive CTE walking forward from the dependent (design D4) and its path
 * is named in the message. The dependent's locked/available state is
 * derived, so it changes immediately.
 */
class AddDependency
{
    use GuardsObjectives;

    public function __construct(private DomainTransaction $transaction) {}

    public function __invoke(Actor $actor, Item $prerequisite, Item $dependent): ItemDependency
    {
        foreach ([$prerequisite, $dependent] as $item) {
            if ($item->objective->user_id !== $actor->user->id) {
                throw (new ModelNotFoundException)->setModel(Item::class, [$item->id]);
            }
        }

        $this->ensureWritable($dependent->objective);

        if ($prerequisite->is($dependent)) {
            throw ValidationException::withMessages(['prerequisite' => 'Una tarea no puede depender de sí misma.']);
        }

        if ($prerequisite->retired_at !== null || $dependent->retired_at !== null) {
            throw ValidationException::withMessages(['prerequisite' => 'Una tarea retirada no se puede enlazar.']);
        }

        return $this->transaction->run($actor, Operation::AddDependency, $dependent, function () use ($prerequisite, $dependent): ItemDependency {
            $exists = ItemDependency::query()
                ->where('prerequisite_id', $prerequisite->id)
                ->where('dependent_id', $dependent->id)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['prerequisite' => 'Esa dependencia ya existe.']);
            }

            $path = $this->cyclePath($prerequisite, $dependent);

            if ($path !== null) {
                throw ValidationException::withMessages([
                    'prerequisite' => 'No se puede: armaría un círculo. Camino: '.$this->describe($path).'.',
                ]);
            }

            return ItemDependency::query()->create([
                'prerequisite_id' => $prerequisite->id,
                'dependent_id' => $dependent->id,
            ]);
        });
    }

    /**
     * The ids of the cycle the new edge would close — prerequisite, dependent,
     * …, prerequisite — or null when the dependent cannot reach the
     * prerequisite through existing edges.
     *
     * @return list<int>|null
     */
    private function cyclePath(Item $prerequisite, Item $dependent): ?array
    {
        $row = DB::selectOne(<<<'SQL'
            WITH RECURSIVE walk (id, path, depth) AS (
                SELECT CAST(? AS BIGINT), CAST(? AS TEXT), 0
                UNION ALL
                SELECT edges.dependent_id, walk.path || ',' || CAST(edges.dependent_id AS TEXT), walk.depth + 1
                FROM item_dependencies AS edges
                INNER JOIN walk ON edges.prerequisite_id = walk.id
                WHERE walk.depth < 10000
            )
            SELECT path FROM walk WHERE id = ? ORDER BY depth LIMIT 1
            SQL, [$dependent->id, (string) $dependent->id, $prerequisite->id]);

        if ($row === null) {
            return null;
        }

        $ids = array_map('intval', explode(',', (string) $row->path));

        return [$prerequisite->id, ...$ids];
    }

    /**
     * "KEY Título → KEY Título → …" for the cycle's items.
     *
     * @param  list<int>  $path
     */
    private function describe(array $path): string
    {
        $items = Item::query()->with('objective')->whereIn('id', array_unique($path))->get()->keyBy('id');

        return collect($path)
            ->map(fn (int $id): string => $items->has($id) ? $items[$id]->key.' '.$items[$id]->title : (string) $id)
            ->implode(' → ');
    }
}
