<?php

use App\Models\Habit;
use App\Models\HabitDay;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
| Phase 4 habit screens in a real browser (mockups 18–21): a habit is
| created only with its 2-minute version, logged from "Hábitos de hoy"
| (mark done, stepper, only the 2 minutes with its undo, restart after a
| miss), inspected on its detail, retired (Phase 6 protocol) and restored
| from "Todos",
| and its identity votes read as a proportion.
*/

test('a habit is created only with its 2-minute version, then logged from today', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/habits/manage');

    $page->assertSee('Todos los hábitos')
        ->click('Nuevo hábito')
        ->assertSee('Nuevo hábito')
        ->fill('input[name="name"]', 'Leer 2 páginas de Control')
        ->click('Una cantidad')
        ->fill('input[name="daily_target"]', '2')
        ->fill('input[name="unit"]', 'páginas')
        ->click('Crear hábito')
        ->assertSee('Falta la versión de 2 minutos. Es lo que vas a hacer los días difíciles.')
        ->assertNoJavascriptErrors();

    expect(Habit::query()->count())->toBe(0);

    $page->fill('input[name="two_minute_version"]', 'Abrir el libro en el separador')
        ->click('Crear hábito')
        ->assertSee('2 min: Abrir el libro en el separador')
        ->assertSee('Nunca dos veces seguidas')
        ->assertNoJavascriptErrors();

    $habit = Habit::query()->sole();

    expect($habit->two_minute_version)->toBe('Abrir el libro en el separador')
        ->and($habit->daily_target)->toBe(2);

    $page = visit('/habits');

    $page->assertSee('Hábitos de hoy')
        ->assertSee('0 de 2 páginas')
        ->click('button[aria-label="Uno más"]')
        ->assertSee('1 de 2 páginas')
        ->click('Solo los 2 minutos')
        ->assertSee('Hecho: 2 minutos.')
        ->assertNoJavascriptErrors();

    $day = $habit->days()->sole();

    expect($day->two_minute_logged)->toBeTrue()
        ->and($day->completed)->toBeFalse()
        ->and($day->accumulated_amount)->toBe(1);

    $page->click('Deshacer')
        ->assertSee('Solo los 2 minutos')
        ->assertNoJavascriptErrors();

    expect($day->fresh()->two_minute_logged)->toBeFalse();
});

test('after a miss the row offers to restart with 2 minutes, never a count of missed days', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->create([
        'name' => 'Dormir 22:00',
        'two_minute_version' => 'Dejar el teléfono cargando fuera del cuarto',
        'created_at' => now()->subDays(20),
    ]);

    $yesterday = Carbon::parse(Habit::todayLocalDate())->subDay();

    foreach (range(2, 12) as $daysAgo) {
        HabitDay::factory()->for($habit)->create(['entry_date' => $yesterday->clone()->subDays($daysAgo - 1)->toDateString()]);
    }

    $this->actingAs($user);

    $page = visit('/habits');

    $page->assertSee('Retomar con 2 minutos')
        ->assertSee('hoy toca volver')
        ->assertDontSee('perdiste')
        ->assertDontSee('faltaste')
        ->click('Retomar con 2 minutos')
        ->assertSee('Hecho: 2 minutos.')
        ->assertDontSee('Retomar con 2 minutos')
        ->assertNoJavascriptErrors();

    expect($habit->fresh()->history()->streak()->state->value)->toBe('ok');
});

test('a habit is retired with a reason from "Todos", listed under Retirados, and restored keeping its history', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->create(['name' => 'Meditar']);
    HabitDay::factory()->for($habit)->create(['entry_date' => Habit::todayLocalDate()->subDays(3)->toDateString()]);
    $this->actingAs($user);

    $page = visit('/habits/manage');

    $page->assertSee('Meditar')
        ->click('Retirar')
        ->assertSee('¿Por qué lo retirás?')
        ->fill('reason', 'demasiado grande para arrancar')
        ->click('Retirar y archivar')
        ->assertNoJavascriptErrors();

    expect($habit->fresh())->toBeNull()
        ->and(Habit::withRetired()->find($habit->id)->retired_at)->not->toBeNull();

    $page = visit('/habits/manage');

    $page->assertSee('Retirados')
        ->assertSee('demasiado grande para arrancar')
        ->assertSee('1 día registrado')
        ->click('Devolver')
        ->assertSee('Todavía no retiraste nada')
        ->assertNoJavascriptErrors();

    expect($habit->fresh()->retired_at)->toBeNull()
        ->and($habit->days()->count())->toBe(1);

    visit('/habits/manage')->assertSee('Meditar')->assertDontSee('demasiado grande para arrancar');
});

test('identity votes read as a proportion per statement', function () {
    $user = User::factory()->create();
    $habit = Habit::factory()->for($user)->create([
        'name' => 'Gimnasio',
        'identity_statement' => 'Soy alguien que entrena',
        'created_at' => now()->subDays(40),
    ]);
    HabitDay::factory()->for($habit)->create(['entry_date' => Habit::todayLocalDate()->subDay()->toDateString()]);
    $this->actingAs($user);

    visit('/habits/identity')
        ->assertSee('Votos de identidad')
        ->assertSee('“Soy alguien que entrena”')
        ->assertSee('1 de 6 votos')
        ->assertDontSee('%')
        ->assertNoJavascriptErrors();
});
