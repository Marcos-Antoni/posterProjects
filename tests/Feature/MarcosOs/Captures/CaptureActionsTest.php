<?php

use App\Actions\Captures\ConvertCaptureToHabit;
use App\Actions\Captures\ConvertCaptureToItem;
use App\Actions\Captures\ConvertCaptureToObjectiveDraft;
use App\Actions\Captures\CreateCapture;
use App\Actions\Retirement\RestoreElement;
use App\Actions\Retirement\RetireElement;
use App\Actions\Support\Actor;
use App\Enums\CaptureSource;
use App\Enums\ObjectiveState;
use App\Http\Resources\NowView;
use App\Models\Capture;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/*
| Task 7.1-7.3 — captures, weekly_priorities, reviews migrations + models,
| CreateCapture, and triage (capture-inbox spec).
*/

test('capturing requires a non-empty text of at most 500 characters, and records the source', function () {
    $owner = User::factory()->create();

    expect(fn () => app(CreateCapture::class)(Actor::ownerWeb($owner), '   ', CaptureSource::Web))
        ->toThrow(ValidationException::class, CreateCapture::EMPTY);

    expect(fn () => app(CreateCapture::class)(Actor::ownerWeb($owner), str_repeat('a', 501), CaptureSource::Web))
        ->toThrow(ValidationException::class);

    $capture = app(CreateCapture::class)(Actor::ownerWeb($owner), '  llamar al dentista  ', CaptureSource::Mobile);

    expect($capture->text)->toBe('llamar al dentista')
        ->and($capture->source)->toBe(CaptureSource::Mobile)
        ->and($capture->triaged_at)->toBeNull()
        ->and($capture->created_at)->not->toBeNull();
});

test('a capture never enters the Now screen', function () {
    $owner = User::factory()->create();
    app(CreateCapture::class)(Actor::ownerWeb($owner), 'llamar al dentista', CaptureSource::Web);

    expect(app(NowView::class)->present($owner))->toBeNull();
});

test('the AI may capture directly (minor tier)', function () {
    $owner = User::factory()->create();

    $capture = app(CreateCapture::class)(Actor::aiMcp($owner), 'anotado por la IA', CaptureSource::Ai);

    expect($capture->source)->toBe(CaptureSource::Ai);
});

test('converting a capture creates an item with a 2-minute version, keeps the link, and leaves the untriaged list', function () {
    $objective = Objective::factory()->withControlPlan()->create();
    $plan = Plan::factory()->for($objective)->create();
    $capture = Capture::factory()->create(['user_id' => $objective->user_id, 'text' => 'ver la clase grabada']);

    $item = app(ConvertCaptureToItem::class)(mosOwner($capture), $capture, $plan, [
        'title' => 'Ver la clase grabada de Física',
        'two_minute_version' => 'abrir la grabación y poner play',
    ]);

    $fresh = $capture->fresh();

    expect($item->plan_id)->toBe($plan->id)
        ->and($fresh->triaged_at)->not->toBeNull()
        ->and($fresh->result_type)->toBe('item')
        ->and($fresh->result_id)->toBe($item->id)
        ->and($fresh->result)->toBeInstanceOf(Item::class)
        ->and(Capture::query()->untriaged()->whereKey($capture->id)->exists())->toBeFalse();
});

test('converting a capture creates a habit and keeps the link', function () {
    $capture = Capture::factory()->create(['text' => 'estirar 5 minutos']);

    $habit = app(ConvertCaptureToHabit::class)(mosOwner($capture), $capture, [
        'name' => 'Estirar 5 minutos',
        'two_minute_version' => 'tocarme la punta de los pies',
        'habit_type' => 'yes_no',
        'recurrence_type' => 'daily',
    ]);

    $fresh = $capture->fresh();

    expect($habit->name)->toBe('Estirar 5 minutos')
        ->and($fresh->result_type)->toBe('habit')
        ->and($fresh->result_id)->toBe($habit->id);
});

test('converting a capture creates a draft objective and keeps the link', function () {
    $capture = Capture::factory()->create(['text' => 'aprender guitarra']);

    $objective = app(ConvertCaptureToObjectiveDraft::class)(mosOwner($capture), $capture, [
        'key' => 'MUSICA',
        'title' => 'Aprender guitarra',
    ]);

    expect($objective->state)->toBe(ObjectiveState::Draft)
        ->and($capture->fresh()->result_type)->toBe('objective');
});

test('a triaged capture cannot be converted again', function () {
    $capture = Capture::factory()->triaged('item', 999)->create();
    $objective = Objective::factory()->withControlPlan()->create(['user_id' => $capture->user_id]);
    $plan = Plan::factory()->for($objective)->create();

    expect(fn () => app(ConvertCaptureToItem::class)(mosOwner($capture), $capture, $plan, [
        'title' => 'x', 'two_minute_version' => 'y',
    ]))->toThrow(ModelNotFoundException::class);
});

test('retiring a capture through the retirement protocol hides it and can restore it', function () {
    $capture = Capture::factory()->create();
    $actor = mosOwner($capture);

    $result = app(RetireElement::class)($actor, $capture, 'ya no me interesa, lo anoté sin pensar');

    expect(Capture::query()->whereKey($capture->id)->exists())->toBeFalse()
        ->and(Capture::withRetired()->whereKey($capture->id)->first()->retired_at)->not->toBeNull();

    app(RestoreElement::class)($actor, $result->retirement);

    expect(Capture::query()->whereKey($capture->id)->exists())->toBeTrue();
});

test('retiring a capture twice is refused', function () {
    $capture = Capture::factory()->create();
    $actor = mosOwner($capture);
    app(RetireElement::class)($actor, $capture, 'ya no me interesa, lo anoté sin pensar');

    expect(fn () => app(RetireElement::class)($actor, $capture, 'otra razón, con más de diez letras'))
        ->toThrow(ModelNotFoundException::class);
});
