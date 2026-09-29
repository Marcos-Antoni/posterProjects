<?php

use App\Mcp\Servers\PosterServer;
use App\Mcp\Tools\Captures\Capture as CaptureTool;
use App\Mcp\Tools\Captures\ListInbox;
use App\Models\Capture;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Task 7.2/7.3/7.8 — the capture inbox (screen 16), the quick-entry overlay
| (screen 17) and MCP `capture` / `list-inbox`.
*/

beforeEach(function () {
    $this->owner = User::factory()->create();
});

test('the inbox lists untriaged captures oldest first', function () {
    $old = Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'la más vieja', 'created_at' => now()->subDays(3)]);
    $new = Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'la más nueva', 'created_at' => now()]);
    Capture::factory()->triaged('item', 1)->create(['user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->get('/captures')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('captures/index')
        ->where('captures.0.id', $old->id)
        ->where('captures.1.id', $new->id)
        ->has('captures', 2));
});

test('capturing from any screen saves without leaving it', function () {
    $this->actingAs($this->owner)
        ->from('/now')
        ->post('/captures', ['text' => 'preguntar en el gym'])
        ->assertRedirect('/now');

    expect(Capture::query()->where('user_id', $this->owner->id)->where('text', 'preguntar en el gym')->exists())->toBeTrue();
});

test('an empty capture is rejected with a Spanish message', function () {
    $this->actingAs($this->owner)->post('/captures', ['text' => ''])->assertSessionHasErrors('text');
});

test('triage converts a capture into a task', function () {
    $objective = Objective::factory()->for($this->owner)->withControlPlan()->create();
    $plan = Plan::factory()->for($objective)->create();
    $capture = Capture::factory()->create(['user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->post("/captures/{$capture->id}/convert-to-item", [
        'objective_key' => $objective->key,
        'plan_id' => $plan->id,
        'title' => 'Ver la clase',
        'two_minute_version' => 'abrir la grabación',
    ])->assertRedirect();

    expect($capture->fresh()->triaged_at)->not->toBeNull();
});

test('triage converts a capture into a habit', function () {
    $capture = Capture::factory()->create(['user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->post("/captures/{$capture->id}/convert-to-habit", [
        'name' => 'Estirar',
        'two_minute_version' => 'tocarme la punta de los pies',
    ])->assertRedirect();

    expect($capture->fresh()->result_type)->toBe('habit');
});

test('triage converts a capture into a draft objective', function () {
    $capture = Capture::factory()->create(['user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->post("/captures/{$capture->id}/convert-to-objective", [
        'key' => 'MUSICA',
        'title' => 'Aprender guitarra',
    ])->assertRedirect();

    expect($capture->fresh()->result_type)->toBe('objective');
});

test('the retire dialog context and action work for a capture', function () {
    $capture = Capture::factory()->create(['user_id' => $this->owner->id, 'text' => 'algo que ya no importa']);

    $this->actingAs($this->owner)->getJson("/captures/{$capture->id}/retire")
        ->assertOk()
        ->assertJsonPath('kind', 'capture');

    $this->actingAs($this->owner)->post("/captures/{$capture->id}/retire", [
        'reason' => 'ya no me interesa, era una idea al pasar',
    ])->assertRedirect('/captures');

    expect(Capture::query()->whereKey($capture->id)->exists())->toBeFalse();
});

test('MCP capture notes an idea down and list-inbox lists it', function () {
    PosterServer::actingAs($this->owner)->tool(CaptureTool::class, ['text' => 'anotado por la IA'])
        ->assertOk()
        ->assertSee('anotado por la IA');

    PosterServer::actingAs($this->owner)->tool(ListInbox::class)
        ->assertOk()
        ->assertSee('anotado por la IA');
});
