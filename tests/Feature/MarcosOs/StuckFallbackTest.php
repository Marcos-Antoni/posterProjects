<?php

use App\Actions\Items\ShrinkTwoMinuteVersion;
use App\Actions\Support\Actor;
use App\Actions\Support\AuditWriter;
use App\Actions\Support\Operation;
use App\Enums\TwoMinuteSource;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/*
| Task 3.5 — "estoy trabado" without AI (now-focus "I'm Stuck Returns One
| Smaller Physical Action"): the owner writes one smaller physical action and
| it becomes the task's new 2-minute version, the previous one kept in
| history. The same action (a minor AI operation) is what Phase 8's
| `shrink-step` will call.
*/

function p3StuckItem(): Item
{
    $owner = User::factory()->create();
    $objective = Objective::factory()->for($owner)->withControlPlan()->create(['key' => 'DIARIO']);

    return Item::factory()->for(Plan::factory()->for($objective)->create())->create([
        'title' => 'Captura 10 min',
        'two_minute_version' => 'abrir el inbox y escribir una línea',
    ]);
}

test('the smaller action becomes the new 2-minute version and the previous one goes to history as the owner\'s', function () {
    $item = p3StuckItem();

    $this->actingAs($item->objective->user)->from('/now')
        ->post(route('objectives.items.two-minute', ['objective' => 'DIARIO', 'item' => 'DIARIO-1']), [
            'two_minute_version' => 'escribir solo el título de una idea',
        ])
        ->assertRedirect('/now')
        ->assertSessionHasNoErrors();

    $item->refresh();
    $history = $item->twoMinuteHistory()->get();

    expect($item->two_minute_version)->toBe('escribir solo el título de una idea')
        ->and($history)->toHaveCount(1)
        ->and($history->first()->text)->toBe('abrir el inbox y escribir una línea')
        ->and($history->first()->source)->toBe(TwoMinuteSource::Owner)
        ->and($item->title)->toBe('Captura 10 min');
});

test('the smaller action is required, trimmed, at most 255 characters and different from the current one', function (mixed $value, string $message) {
    $item = p3StuckItem();

    $this->actingAs($item->objective->user)->from('/now')
        ->post(route('objectives.items.two-minute', ['objective' => 'DIARIO', 'item' => 'DIARIO-1']), ['two_minute_version' => $value])
        ->assertSessionHasErrors(['two_minute_version' => $message]);

    expect($item->refresh()->two_minute_version)->toBe('abrir el inbox y escribir una línea')
        ->and($item->twoMinuteHistory()->count())->toBe(0);
})->with([
    'empty' => ['', 'Escribí una sola acción física, la más chica que puedas hacer ahora.'],
    'blank' => ['   ', 'Escribí una sola acción física, la más chica que puedas hacer ahora.'],
    'too long' => [str_repeat('a', 256), 'La acción puede tener hasta 255 caracteres.'],
    'same' => ['  abrir el inbox y escribir una línea ', 'Escribí una acción distinta de la actual: más chica todavía.'],
]);

test('a done item is not shrunk', function () {
    $item = p3StuckItem();
    $item->update(['completed_at' => now()]);

    $this->actingAs($item->objective->user)->from('/now')
        ->post(route('objectives.items.two-minute', ['objective' => 'DIARIO', 'item' => 'DIARIO-1']), ['two_minute_version' => 'otra cosa'])
        ->assertSessionHasErrors('item');
});

test('another owner\'s item is a 404', function () {
    $item = p3StuckItem();

    $this->actingAs(User::factory()->create())
        ->post(route('objectives.items.two-minute', ['objective' => 'DIARIO', 'item' => 'DIARIO-1']), ['two_minute_version' => 'otra cosa'])
        ->assertNotFound();

    expect($item->refresh()->two_minute_version)->toBe('abrir el inbox y escribir una línea');
});

test('shrinking is a minor operation: an AI actor applies it directly, recorded as ai and audited', function () {
    $item = p3StuckItem();
    $audit = new class implements AuditWriter
    {
        public array $records = [];

        public function record(Actor $actor, Operation $operation, Model $target, array $before, array $after): void
        {
            $this->records[] = [$operation, $before['two_minute_version'] ?? null, $after['two_minute_version'] ?? null];
        }
    };
    app()->instance(AuditWriter::class, $audit);

    app(ShrinkTwoMinuteVersion::class)(Actor::aiMcp($item->objective->user), $item, 'escribir solo el título');

    expect(Operation::ShrinkStep->tier()->value)->toBe('minor')
        ->and($item->refresh()->two_minute_version)->toBe('escribir solo el título')
        ->and($item->twoMinuteHistory()->first()->source)->toBe(TwoMinuteSource::Ai)
        ->and($audit->records)->toBe([[Operation::ShrinkStep, 'abrir el inbox y escribir una línea', 'escribir solo el título']]);
});
