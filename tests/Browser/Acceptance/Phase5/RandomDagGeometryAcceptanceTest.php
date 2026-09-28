<?php

/*
| Phase 5 acceptance round 2 (independent tester) — seeded random DAGs never
| used by the implementer: 2–4 objectives, 3–6 plans each, 30–80 items,
| edges in a random topological order (so many point to earlier plans), and
| several cross-objective unlocks. On the rendered SVG of screens 8 and 9:
| no two marks overlap; no path passes within radius + margin of a station
| it does not connect; every straight piece is at 0°, 45° or 90° and every
| curve is a small rounded corner; stub lines never cross another line; two
| unrelated paths never run on top of each other.
*/

use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

/**
 * @return array{0: User, 1: list<string>}
 */
function p5rSeed(int $seed): array
{
    mt_srand($seed);
    $owner = User::factory()->create();
    $objectiveCount = mt_rand(2, 4);
    $total = mt_rand(30, 80);
    $keys = [];
    $items = [];

    foreach (range(0, $objectiveCount - 1) as $o) {
        $key = 'R'.$seed.chr(65 + $o);
        $keys[] = $key;
        $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => $key, 'title' => "Objetivo {$o}", 'position' => $o]);
        $plans = [];
        foreach (range(1, mt_rand(3, 6)) as $p) {
            $plans[] = Plan::factory()->for($objective)->create(['title' => "Plan {$p}", 'position' => $p]);
        }
        $share = intdiv($total, $objectiveCount);
        foreach (range(1, $share) as $i) {
            $items[] = ['objective' => $o, 'item' => Item::factory()->for($plans[mt_rand(0, count($plans) - 1)])->create(['title' => "{$key} t{$i}"])];
        }
    }

    // A random topological rank per item: edges go from lower to higher rank.
    $rank = range(0, count($items) - 1);
    shuffle($rank);
    $pairs = [];
    $crossLeft = mt_rand(2, 5); // "several" cross-objective unlocks
    $attempts = count($items) * 2;
    for ($n = 0; $n < $attempts; $n++) {
        $a = mt_rand(0, count($items) - 1);
        $b = mt_rand(0, count($items) - 1);
        if ($a === $b) {
            continue;
        }
        $cross = $items[$a]['objective'] !== $items[$b]['objective'];
        if ($cross) {
            if ($crossLeft === 0) {
                continue;
            }
            $crossLeft--;
        }
        [$from, $to] = $rank[$a] < $rank[$b] ? [$a, $b] : [$b, $a];
        $pairs["{$from}-{$to}"] = [$from, $to];
    }
    foreach ($pairs as [$from, $to]) {
        ItemDependency::query()->create(['prerequisite_id' => $items[$from]['item']->id, 'dependent_id' => $items[$to]['item']->id]);
    }

    return [$owner, $keys];
}

const P5R_GEOMETRY = <<<'JS'
(() => {
    const svg = document.querySelector('svg.tr[role="group"]');
    const visible = (el) => getComputedStyle(el).display !== 'none' && !el.closest('[style*="display: none"]');
    const nodes = [...svg.querySelectorAll('g.nd')].filter(visible).map((g) => {
        const s = g.querySelector('.selr');
        return { id: g.dataset.id, x: +s.getAttribute('cx'), y: +s.getAttribute('cy'), r: +s.getAttribute('r') - 7 };
    });
    const bad = [];
    for (let i = 0; i < nodes.length; i++) for (let j = i + 1; j < nodes.length; j++) {
        const a = nodes[i], b = nodes[j];
        if (Math.hypot(a.x - b.x, a.y - b.y) < a.r + b.r + 2) bad.push(`marks overlap: ${a.id} / ${b.id}`);
    }
    const at = (q) => nodes.find((n) => Math.hypot(q.x - n.x, q.y - n.y) <= n.r + 3)?.id;
    const paths = [...svg.querySelectorAll('path.e')].filter(visible).map((el, k) => {
        const len = el.getTotalLength();
        const pts = [];
        for (let t = 0; t <= len; t += 1.5) pts.push(el.getPointAtLength(t));
        pts.push(el.getPointAtLength(len));
        const start = pts[0], end = pts[pts.length - 1];
        return { k, el, pts, start, end, stub: el.classList.contains('e-stub'), ends: [at(start), at(end)].filter(Boolean), d: el.getAttribute('d') };
    });
    const MARGIN = 3;
    for (const p of paths) {
        const name = `path ${p.k} (${p.ends.join('→')})`;
        for (const n of nodes) {
            if (p.ends.includes(n.id)) continue;
            if (p.pts.some((q) => Math.hypot(q.x - n.x, q.y - n.y) < n.r + MARGIN)) bad.push(`${name} passes over ${n.id}`);
        }
        // angles: parse M/L/Q commands
        const tokens = p.d.match(/[MLQ][^MLQ]*/g) || [];
        let cur = null;
        for (const t of tokens) {
            const nums = t.slice(1).trim().split(/[ ,]+/).map(Number);
            if (t[0] === 'M') { cur = { x: nums[0], y: nums[1] }; continue; }
            if (t[0] === 'L') {
                const nx = nums[0], ny = nums[1];
                const dx = nx - cur.x, dy = ny - cur.y;
                const len = Math.hypot(dx, dy);
                if (len >= 4) {
                    const ang = Math.abs(Math.atan2(dy, dx) * 180 / Math.PI) % 45;
                    if (Math.min(ang, 45 - ang) > 1.5) bad.push(`${name} has a ${(Math.atan2(dy, dx) * 180 / Math.PI).toFixed(1)}° segment`);
                }
                cur = { x: nx, y: ny };
            }
            if (t[0] === 'Q') {
                const ex = nums[2], ey = nums[3];
                if (Math.hypot(ex - cur.x, ey - cur.y) > 20) bad.push(`${name} has a long curve (not a corner)`);
                cur = { x: ex, y: ey };
            }
        }
    }
    const nearNode = (q) => nodes.some((n) => Math.hypot(q.x - n.x, q.y - n.y) < n.r + 14);
    // spatial hash of every path point away from stations
    const grid = new Map();
    paths.forEach((p) => {
        p.far = p.pts.filter((q) => !nearNode(q));
        for (const q of p.far) {
            const key = `${Math.round(q.x / 2)},${Math.round(q.y / 2)}`;
            if (!grid.has(key)) grid.set(key, []);
            grid.get(key).push([p.k, q]);
        }
    });
    const touch = new Map();
    for (const p of paths) {
        for (const q of p.far) {
            const gx = Math.round(q.x / 2), gy = Math.round(q.y / 2);
            const seen = new Set();
            for (let ix = -1; ix <= 1; ix++) for (let iy = -1; iy <= 1; iy++) {
                for (const [k, o] of grid.get(`${gx + ix},${gy + iy}`) || []) {
                    if (k <= p.k || seen.has(k)) continue;
                    if (Math.hypot(o.x - q.x, o.y - q.y) < 1.2) { seen.add(k); touch.set(`${p.k}|${k}`, (touch.get(`${p.k}|${k}`) || 0) + 1); }
                }
            }
        }
    }
    for (const [pair, count] of touch) {
        const [i, j] = pair.split('|').map(Number);
        const a = paths[i], b = paths[j];
        if (a.ends.some((e) => b.ends.includes(e))) continue;
        // Cross-objective unlocks between the same two tracks are bundled into one connector (visual contract, P5-R3-2 menor).
        const line = (id) => (id || '').replace(/^objetivo:/, '').replace(/-\d+$/, '');
        if (!a.stub && !b.stub && a.ends.length === 2 && b.ends.length === 2 && line(a.ends[0]) === line(b.ends[0]) && line(a.ends[1]) === line(b.ends[1]) && line(a.ends[0]) !== line(a.ends[1])) continue;
        if (a.stub || b.stub) bad.push(`stub line crosses: path ${i} (${a.ends.join('→')}) / path ${j} (${b.ends.join('→')})`);
        if (count * 1.5 > 24) bad.push(`paths ${i} (${a.ends.join('→')}) and ${j} (${b.ends.join('→')}) run together ~${Math.round(count * 1.5)}px`);
    }
    return JSON.stringify([...new Set(bad)]);
})()
JS;

function p5rShot(mixed $page, string $name): void
{
    $page->screenshot(fullPage: true, filename: 'p5r-'.$name);
}

test('random DAG: every objective track keeps the geometric invariants', function (int $seed) {
    [$owner, $keys] = p5rSeed($seed);
    $this->actingAs($owner);

    $failures = [];
    foreach ($keys as $key) {
        $page = visit("/map/{$key}")->resize(1440, 1000);
        $page->assertNoJavascriptErrors();
        if ($key === $keys[0]) {
            p5rShot($page, "{$seed}-08");
        }
        foreach (json_decode($page->script(P5R_GEOMETRY), true) as $problem) {
            $failures[] = "{$key}: {$problem}";
        }
    }

    expect($failures)->toBe([]);
})->with([11, 23, 37, 58, 71, 94]);

test('random DAG: the global map keeps the geometric invariants', function (int $seed) {
    [$owner] = p5rSeed($seed);
    $this->actingAs($owner);

    $page = visit('/map')->resize(1440, 1000);
    $page->assertNoJavascriptErrors();
    p5rShot($page, "{$seed}-09");

    expect(json_decode($page->script(P5R_GEOMETRY), true))->toBe([]);
})->with([11, 23, 37, 58, 71, 94]);

test('random DAG: the global map with one objective collapsed keeps the invariants', function () {
    [$owner, $keys] = p5rSeed(23);
    $this->actingAs($owner);

    $page = visit('/map')->resize(1440, 1000);
    $page->click('[data-id="objetivo:'.$keys[0].'"] .hit')->click('Contraer esta línea')->assertNoJavascriptErrors();

    expect(json_decode($page->script(P5R_GEOMETRY), true))->toBe([]);
});

// --- round 3: new seeds, realistic sizes, "+N" discoverability ---

test('round 3 new seeds: objective tracks and global map keep the invariants', function (int $seed) {
    [$owner, $keys] = p5rSeed($seed);
    $this->actingAs($owner);

    $failures = [];
    foreach ($keys as $key) {
        $page = visit("/map/{$key}")->resize(1440, 1000);
        $page->assertNoJavascriptErrors();
        if ($key === $keys[0]) {
            p5rShot($page, "{$seed}-08");
        }
        foreach (json_decode($page->script(P5R_GEOMETRY), true) as $problem) {
            $failures[] = "{$key}: {$problem}";
        }
    }
    $page = visit('/map')->resize(1440, 1000);
    p5rShot($page, "{$seed}-09");
    foreach (json_decode($page->script(P5R_GEOMETRY), true) as $problem) {
        $failures[] = "global: {$problem}";
    }
    $page = visit('/map?collapsed='.$keys[0])->resize(1440, 1000);
    foreach (json_decode($page->script(P5R_GEOMETRY), true) as $problem) {
        $failures[] = "global collapsed: {$problem}";
    }

    expect($failures)->toBe([]);
})->with([131, 202, 317, 444]);

/**
 * Marco's real size: one objective, 2–4 plans, 10–25 items, ~1.2 edges per
 * item, some done at the top and one Now task.
 */
function p5rSmall(int $seed): array
{
    mt_srand($seed);
    $owner = User::factory()->create();
    $key = 'S'.$seed;
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => $key]);
    $plans = [];
    foreach (range(1, mt_rand(2, 4)) as $p) {
        $plans[] = Plan::factory()->for($objective)->create(['position' => $p]);
    }
    $count = mt_rand(10, 25);
    $items = [];
    foreach (range(0, $count - 1) as $i) {
        // Items created in plan order, mostly chained forward like a real plan.
        $items[] = Item::factory()->for($plans[min(count($plans) - 1, intdiv($i * count($plans), $count))])->create(['title' => "Paso {$i}"]);
    }
    $pairs = [];
    foreach (range(1, $count - 1) as $i) {
        $pairs[] = [max(0, $i - mt_rand(1, 3)), $i];
        if (mt_rand(0, 4) === 0) {
            $pairs[] = [mt_rand(0, $i - 1), $i];
        }
    }
    foreach (array_unique(array_map(fn ($p) => $p[0].'-'.$p[1], $pairs)) as $pair) {
        [$a, $b] = explode('-', $pair);
        ItemDependency::query()->create(['prerequisite_id' => $items[(int) $a]->id, 'dependent_id' => $items[(int) $b]->id]);
    }
    $done = intdiv($count, 4);
    foreach (range(0, $done - 1) as $i) {
        $items[$i]->update(['completed_at' => now()]);
    }

    return [$owner, $key];
}

test('realistic size (10–25 items): clean geometry, at most 6 lanes, nothing hidden', function (int $seed) {
    [$owner, $key] = p5rSmall($seed);
    $this->actingAs($owner);

    $page = visit("/map/{$key}")->resize(1440, 1000);
    $page->assertNoJavascriptErrors();
    p5rShot($page, "small-{$seed}");

    $lanes = $page->script('new Set([...document.querySelectorAll("svg.tr g.nd:not(.stub):not(.more):not(.ret)")].map(g => g.querySelector(".selr").getAttribute("cx"))).size');

    expect(json_decode($page->script(P5R_GEOMETRY), true))->toBe([])
        ->and($lanes)->toBeLessThanOrEqual(6)
        ->and($page->script('document.querySelectorAll("svg.tr g.more").length'))->toBe(0);
})->with([5, 17, 29, 41, 53, 67, 79, 83]);

test('a "+N" mark is keyboard reachable and opens the panel listing the hidden unlocks', function () {
    [$owner, $keys] = p5rSeed(202);
    $this->actingAs($owner);

    $found = false;
    foreach ($keys as $key) {
        $page = visit("/map/{$key}")->resize(1440, 1000);
        $marks = $page->script('[...document.querySelectorAll("svg.tr g.more")].map(g => g.dataset.id)');
        if ($marks === []) {
            continue;
        }
        $found = true;
        $hidden = json_decode($page->script('JSON.stringify(document.querySelector("svg.tr g.more").getAttribute("tabindex"))'), true);
        expect($hidden)->toBe('0');

        $mark = $marks[0];
        $page->keys("[data-id=\"{$mark}\"]", 'Enter')->assertPresent('.gside h2');
        $panel = $page->script('document.querySelector(".gside").innerText');
        $props = test()->actingAs($owner)->get("/map/{$key}")->viewData('page')['props'];
        $anchor = substr($mark, strlen('mas:'));
        $titles = array_column($props['graph']['nodes'], 'title', 'key') + array_column($props['graph']['stubs'], 'title', 'key');
        foreach ($props['hidden'] as $edge) {
            if (str_contains($anchor, $edge['from'])) {
                expect($panel)->toContain($titles[$edge['to']]);
            }
        }
        break;
    }

    expect($found)->toBeTrue('seed 202 was expected to hide at least one edge');
});

// --- round 4: hub graphs (Now with 8–12 prerequisites and dependents) exceed the lane cap ---

test('round 4 hub graphs keep the invariants when the lane cap is exceeded', function (int $seed) {
    require_once __DIR__.'/../../../Feature/Acceptance/Phase5/ProtectedEdgesAcceptanceTest.php';
    [$owner, $key] = p5pHub($seed, 'B'.$seed);
    $this->actingAs($owner);

    $page = visit("/map/{$key}")->resize(1440, 1000);
    $page->assertNoJavascriptErrors();
    p5rShot($page, "hub-{$seed}");

    expect(json_decode($page->script(P5R_GEOMETRY), true))->toBe([]);
})->with([4127, 4253, 4391]);
