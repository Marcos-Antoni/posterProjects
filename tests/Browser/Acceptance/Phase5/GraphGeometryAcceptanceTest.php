<?php

/*
| Phase 5 acceptance (independent tester) — the rendered track in a real
| browser: for weird DAGs no two marks overlap, no segment runs through a
| station it does not connect, no two segments run on top of each other, and
| the page never scrolls sideways (1440, 768, 390; light and dark).
*/

use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

const P5B_SHOTS = '/tmp/claude-1000/-home-marco/f85743e8-7223-47de-beea-af08f2db35ef/scratchpad/shots/phase5-test/';

function p5bLink(Item $a, Item $b): void
{
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);
}

/**
 * @return array{0: User, 1: string}
 */
function p5bSeed(string $shape): array
{
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'RARO', 'title' => 'Grafo raro']);
    $plans = [];
    foreach (range(1, 3) as $n) {
        $plans[$n] = Plan::factory()->for($objective)->create(['title' => "Plan {$n}", 'position' => $n]);
    }
    $make = fn (int $plan, string $title) => Item::factory()->for($plans[$plan])->create(['title' => $title]);

    switch ($shape) {
        case 'no-edges':
            // The most common real case: plans full of independent tasks.
            foreach (range(1, 3) as $i) {
                $make(1, "P1 tarea {$i}");
            }
            foreach (range(1, 2) as $i) {
                $make(2, "P2 tarea {$i}");
            }
            $make(3, 'P3 tarea');
            break;
        case 'skip-plan':
            // A → D skips plan 2, whose tasks are unrelated.
            $a = $make(1, 'A');
            $make(2, 'X suelta');
            $make(2, 'Y suelta');
            $d = $make(3, 'D');
            p5bLink($a, $d);
            break;
        case 'fan-out-8':
            $root = $make(1, 'Raíz');
            $join = null;
            $leaves = [];
            foreach (range(1, 8) as $i) {
                $leaves[] = $make(2, "Rama {$i}");
            }
            $join = $make(3, 'Unión');
            foreach ($leaves as $leaf) {
                p5bLink($root, $leaf);
                p5bLink($leaf, $join);
            }
            break;
        case 'diamonds':
            $prev = $make(1, 'Inicio');
            foreach (range(1, 4) as $i) {
                $l = $make(2, "Izq {$i}");
                $r = $make(2, "Der {$i}");
                $j = $make(2, "Junta {$i}");
                p5bLink($prev, $l);
                p5bLink($prev, $r);
                p5bLink($l, $j);
                p5bLink($r, $j);
                $prev = $j;
            }
            break;
        case 'back-to-earlier-plan':
            // Edges from a later plan to an earlier one, plus a transitive shortcut.
            $a = $make(1, 'A');
            $b = $make(1, 'B');
            $x = $make(3, 'X');
            $y = $make(2, 'Y');
            p5bLink($x, $a);
            p5bLink($a, $b);
            p5bLink($x, $b);
            p5bLink($y, $b);
            break;
        case 'chain-40':
            $prev = null;
            foreach (range(1, 40) as $i) {
                $item = $make(1 + intdiv($i - 1, 14), "Paso {$i}");
                if ($prev) {
                    p5bLink($prev, $item);
                }
                $prev = $item;
            }
            break;
        case 'stubs':
            // Many externals around one station, both directions, plus a chain.
            $other = Objective::factory()->for($owner)->create(['key' => 'OTRO', 'title' => 'Otro']);
            $op = Plan::factory()->for($other)->create();
            $a = $make(1, 'A');
            $b = $make(1, 'B');
            $c = $make(1, 'C');
            p5bLink($a, $b);
            p5bLink($b, $c);
            foreach (range(1, 4) as $i) {
                p5bLink(Item::factory()->for($op)->create(['title' => "Ext pre {$i}"]), $b);
                p5bLink($b, Item::factory()->for($op)->create(['title' => "Ext dep {$i}"]));
            }
            p5bLink(Item::factory()->for($op)->create(['title' => 'Ext pre C']), $c);
            p5bLink($a, Item::factory()->for($op)->create(['title' => 'Ext dep A']));
            break;
    }

    return [$owner, 'RARO'];
}

/** Collisions measured on the rendered SVG, as a JSON list of strings. */
const P5B_GEOMETRY = <<<'JS'
(() => {
    const svg = document.querySelector('svg.tr[role="group"]');
    const nodes = [...svg.querySelectorAll('g.nd')].filter((g) => getComputedStyle(g).display !== 'none').map((g) => {
        const s = g.querySelector('.selr');
        return { id: g.dataset.id, x: +s.getAttribute('cx'), y: +s.getAttribute('cy'), r: +s.getAttribute('r') - 7 };
    });
    const bad = [];
    for (let i = 0; i < nodes.length; i++) {
        for (let j = i + 1; j < nodes.length; j++) {
            const a = nodes[i], b = nodes[j];
            if (Math.hypot(a.x - b.x, a.y - b.y) < a.r + b.r + 2) bad.push(`marks overlap: ${a.id} / ${b.id}`);
        }
    }
    const paths = [...svg.querySelectorAll('path.e')].map((p) => {
        const len = p.getTotalLength();
        const pts = [];
        for (let t = 0; t <= len; t += 2) pts.push(p.getPointAtLength(t));
        pts.push(p.getPointAtLength(len));
        return { pts, start: pts[0], end: pts[pts.length - 1] };
    }).map((p) => {
        const at = (q) => nodes.find((n) => Math.hypot(q.x - n.x, q.y - n.y) <= n.r + 3)?.id;
        return { ...p, ends: [at(p.start), at(p.end)].filter(Boolean) };
    });
    paths.forEach((p, k) => {
        for (const n of nodes) {
            if (Math.hypot(p.start.x - n.x, p.start.y - n.y) <= n.r + 3 || Math.hypot(p.end.x - n.x, p.end.y - n.y) <= n.r + 3) continue;
            if (p.pts.some((q) => Math.hypot(q.x - n.x, q.y - n.y) < n.r)) { bad.push(`segment ${k} crosses ${n.id}`); }
        }
    });
    const near = (q) => nodes.some((n) => Math.hypot(q.x - n.x, q.y - n.y) < n.r + 14);
    for (let i = 0; i < paths.length; i++) {
        for (let j = i + 1; j < paths.length; j++) {
            // Branches leaving or reaching the same station may share their first stretch (a split/rejoin).
            if (paths[i].ends.some((e) => paths[j].ends.includes(e))) continue;
            const shared = paths[i].pts.filter((q) => !near(q) && paths[j].pts.some((o) => Math.hypot(o.x - q.x, o.y - q.y) < 1.5)).length;
            if (shared * 2 > 30) bad.push(`segments ${i} and ${j} run together for ~${shared * 2}px`);
        }
    }
    return JSON.stringify(bad);
})()
JS;

test('the objective track has no overlapping marks or segments for weird DAGs', function (string $shape) {
    [$owner, $key] = p5bSeed($shape);
    $this->actingAs($owner);

    $page = visit("/map/{$key}")->resize(1440, 1000);
    $page->assertNoJavascriptErrors();
    $page->screenshot(fullPage: true, filename: 'p5t-'.$shape);

    expect(json_decode($page->script(P5B_GEOMETRY), true))->toBe([]);
})->with(['no-edges', 'skip-plan', 'fan-out-8', 'diamonds', 'back-to-earlier-plan', 'chain-40', 'stubs']);

test('the global map has no overlapping marks or segments with several objectives and cross edges', function () {
    [$owner] = p5bSeed('diamonds');
    $second = Objective::factory()->for($owner)->create(['key' => 'DOS', 'title' => 'Segundo', 'position' => 5]);
    $plan = Plan::factory()->for($second)->create();
    $x = Item::factory()->for($plan)->create(['title' => 'X']);
    $y = Item::factory()->for($plan)->create(['title' => 'Y']);
    $z = Item::factory()->for($plan)->create(['title' => 'Z']);
    p5bLink($x, $y);
    $raro = Item::query()->where('title', 'Junta 2')->firstOrFail();
    p5bLink($raro, $z);
    p5bLink($x, Item::query()->where('title', 'Izq 3')->firstOrFail());
    $this->actingAs($owner);

    $page = visit('/map')->resize(1440, 1000);
    $page->assertNoJavascriptErrors();
    $page->screenshot(fullPage: true, filename: 'p5t-global-cross');

    expect(json_decode($page->script(P5B_GEOMETRY), true))->toBe([]);
});

test('both graphs fit the viewport width without horizontal page scroll', function (int $width, string $mode, string $uri) {
    [$owner] = p5bSeed('fan-out-8');
    $owner->update(['appearance' => $mode === 'dark' ? 'dark' : 'light']);
    $this->actingAs($owner);

    $page = visit($uri)->resize($width, 900);
    $page->assertNoJavascriptErrors();
    $page->screenshot(fullPage: true, filename: 'p5t-'.trim(str_replace('/', '-', $uri), '-')."-{$width}-{$mode}");

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
})->with([1440, 768, 390])->with(['light', 'dark'])->with(['/map/RARO', '/map']);
