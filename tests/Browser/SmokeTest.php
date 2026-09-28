<?php

use App\Models\ControlMapEntry;
use App\Models\Habit;
use App\Models\Item;
use App\Models\ItemDependency;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;

test('the login page renders without javascript errors', function () {
    $page = visit('/login');

    $page->assertSee('Entrar')
        ->assertNoJavascriptErrors();
});

test('every key authenticated page renders without javascript errors', function () {
    $user = User::factory()->create();

    $objective = Objective::factory()->for($user)->withControlPlan()->create(['key' => 'SMOKE']);
    ControlMapEntry::factory()->for($objective, 'plannable')->create();
    $plan = Plan::factory()->for($objective)->withControlPlan()->create(['level' => 1]);
    $done = Item::factory()->for($plan)->done()->create();
    $next = Item::factory()->for($plan)->create(['target_date' => today()->subDay()]);
    $milestone = Item::factory()->for($plan)->milestone()->create();
    ItemDependency::query()->create(['prerequisite_id' => $done->id, 'dependent_id' => $next->id]);
    ItemDependency::query()->create(['prerequisite_id' => $next->id, 'dependent_id' => $milestone->id]);

    $habit = Habit::factory()->for($user)->quantitative('pages', 20)->daily()->create();
    $habit->days()->create([
        'entry_date' => Habit::todayLocalDate(),
        'accumulated_amount' => 5,
        'completion_percent' => 25,
        'completed' => false,
    ]);

    $this->actingAs($user);

    $pages = visit([
        '/now',
        '/objectives',
        '/objectives/create',
        '/objectives/SMOKE',
        '/objectives/SMOKE/edit',
        "/objectives/SMOKE/plans/{$plan->id}",
        '/objectives/SMOKE/plans/create',
        "/objectives/SMOKE/plans/{$plan->id}/edit",
        '/objectives/SMOKE/items/SMOKE-1',
        '/objectives/SMOKE/items/SMOKE-2',
        '/objectives/SMOKE/items/SMOKE-3',
        '/habits',
        '/habits/manage',
        "/habits/{$habit->id}",
        '/settings/mcp-token',
        '/settings/mobile-token',
        '/settings/appearance',
        '/confirm-password',
    ]);

    $pages->assertNoJavascriptErrors();
});
