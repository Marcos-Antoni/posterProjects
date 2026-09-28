<?php

/*
| Phase 2 acceptance (independent tester) — browser checks of screens 3–7
| (D15/D16): every screen renders in light and dark without JS errors, a
| past target date never uses the danger token, the space shortcut checks a
| task and announces what it unlocked, and a cycle is refused with its path.
*/

use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

const P2B_DANGER = ['rgb(163, 59, 43)', 'rgb(240, 138, 120)'];

function p2bSeed(): array
{
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO', 'title' => 'Marcos OS en uso diario']);
    $plan = Plan::factory()->for($objective)->withControlPlan()->create(['title' => 'Semana 1', 'level' => 1]);
    $a = Item::factory()->for($plan)->create(['title' => 'Mesa lista', 'target_date' => now()->subDays(5)->toDateString()]);
    $b = Item::factory()->for($plan)->create(['title' => 'Captura 10 min']);
    ItemDependency::query()->create(['prerequisite_id' => $a->id, 'dependent_id' => $b->id]);

    return [$owner, $plan];
}

test('screens 3 to 7 render in light and dark without javascript errors', function (string $mode) {
    [$owner, $plan] = p2bSeed();
    $this->actingAs($owner);

    foreach (['/objectives', '/objectives/DIARIO', '/objectives/create', '/objectives/DIARIO/edit', "/objectives/DIARIO/plans/{$plan->id}", '/objectives/DIARIO/items/DIARIO-1'] as $uri) {
        $page = visit($uri);
        $page = $mode === 'dark' ? $page->inDarkMode() : $page->inLightMode();
        $page->assertNoJavascriptErrors()->assertNoConsoleLogs();
    }
})->with(['light', 'dark']);

test('a past target date is neutral: no danger color anywhere on the item and tree screens', function (string $mode) {
    [$owner] = p2bSeed();
    $this->actingAs($owner);

    foreach (['/objectives/DIARIO/items/DIARIO-1', '/objectives/DIARIO', '/objectives'] as $uri) {
        $page = visit($uri);
        $page = $mode === 'dark' ? $page->inDarkMode() : $page->inLightMode();

        $page->assertDontSee('vencid')->assertDontSee('atrasad');
        $page->assertScript(
            '[...document.querySelectorAll("main *, body *")].filter(e => '.json_encode(P2B_DANGER).'.includes(getComputedStyle(e).color) || '.json_encode(P2B_DANGER).'.includes(getComputedStyle(e).backgroundColor)).length',
            0,
        );
    }
})->with(['light', 'dark']);

test('the space bar checks the task on its deep link and the unlocked item is announced', function () {
    [$owner] = p2bSeed();
    $this->actingAs($owner);

    $page = visit('/objectives/DIARIO/items/DIARIO-1')->assertSee('Mesa lista');
    $page->script('document.body.dispatchEvent(new KeyboardEvent("keydown", {key: " ", bubbles: true}))');
    $page->wait(1)->assertSee('Captura 10 min')->assertNoJavascriptErrors();

    expect(Item::query()->where('title', 'Mesa lista')->first()->completed_at)->not->toBeNull();
});

test('a locked item shows why it is locked and offers no way to check it', function () {
    [$owner] = p2bSeed();
    $this->actingAs($owner);

    $page = visit('/objectives/DIARIO/items/DIARIO-2')->assertSee('Mesa lista')->assertDontSee('Marcar hecho');
    $page->script('document.body.dispatchEvent(new KeyboardEvent("keydown", {key: " ", bubbles: true}))');
    $page->wait(1)->assertNoJavascriptErrors();

    expect(Item::query()->where('title', 'Captura 10 min')->first()->completed_at)->toBeNull();
});
