<?php

/*
| Phase 4 acceptance (independent tester) — browser checks of screens 18–21
| (D15/D16): each renders in light and dark at 1440 and 768 without JS
| errors (screenshots kept for the visual audit), a missed habit offers
| "Retomar con 2 minutos" with no missed-day count and never in the danger
| colour, the restart logs only the 2-minute version, the identity screen
| speaks in proportions ("N de M"), and a level-up is only a suggestion the
| owner accepts.
*/

use App\Models\Habit;
use App\Models\HabitDay;
use App\Models\Objective;
use App\Models\User;

const P4B_DANGER = ['rgb(163, 59, 43)', 'rgb(240, 138, 120)'];

function p4bDay(Habit $habit, int $daysAgo, string $how = 'done'): void
{
    HabitDay::factory()->create([
        'habit_id' => $habit->id,
        'entry_date' => Habit::todayLocalDate()->subDays($daysAgo)->toDateString(),
        'accumulated_amount' => $how === 'done' ? max(1, (int) $habit->daily_target) : 0,
        'peak_amount' => $how === 'done' ? max(1, (int) $habit->daily_target) : 0,
        'completion_percent' => $how === 'done' ? 100 : 0,
        'completed' => $how === 'done',
        'two_minute_logged' => $how === 'two',
    ]);
}

/**
 * @return array{0: User, 1: Habit, 2: Habit}
 */
function p4bSeed(): array
{
    $user = User::factory()->create();
    $objective = Objective::factory()->for($user)->create(['key' => 'SALUD', 'title' => 'Salud de base', 'identity_statement' => 'Soy alguien que se mueve']);
    $created = now()->subDays(40);

    $walk = Habit::factory()->for($user)->create([
        'name' => 'Caminar 20 minutos',
        'two_minute_version' => 'Ponerme las zapatillas',
        'objective_id' => $objective->id,
        'level' => 1,
        'level_started_on' => Habit::todayLocalDate()->subDays(30)->toDateString(),
        'level_ladder' => [
            ['label' => '2 min', 'target' => null, 'two_minute_version' => 'Ponerme las zapatillas'],
            ['label' => '10 min', 'target' => null, 'two_minute_version' => 'Salir a la puerta'],
        ],
        'created_at' => $created,
    ]);

    foreach (range(2, 25) as $ago) {
        if ($ago !== 9) {
            p4bDay($walk, $ago, $ago % 5 === 0 ? 'two' : 'done');
        }
    }

    $read = Habit::factory()->for($user)->quantitative('páginas', 30)->create([
        'name' => 'Leer',
        'two_minute_version' => 'Abrir el libro en el separador',
        'identity_statement' => 'Soy alguien que lee',
        'created_at' => $created,
    ]);

    foreach (range(3, 12) as $ago) {
        p4bDay($read, $ago);
    }

    $gym = Habit::factory()->for($user)->timesPerWeek(3)->create([
        'name' => 'Gimnasio',
        'two_minute_version' => 'Preparar la mochila',
        'objective_id' => $objective->id,
        'created_at' => $created,
    ]);

    foreach ([1, 3, 8, 9, 11, 15, 16, 18] as $ago) {
        p4bDay($gym, $ago);
    }

    return [$user, $walk, $read];
}

test('screens 18–21 render in light and dark at 1440 and 768 without javascript errors', function (string $mode, int $width) {
    [$user, $walk] = p4bSeed();
    $this->actingAs($user);

    foreach (['18' => '/habits', '19' => '/habits/manage', '20' => "/habits/{$walk->id}", '20create' => '/habits/create', '21' => '/habits/identity'] as $screen => $uri) {
        $page = visit($uri);
        $page = ($mode === 'dark' ? $page->inDarkMode() : $page->inLightMode())->resize($width, 1000);
        $page->assertNoJavascriptErrors()
            ->assertNoConsoleLogs()
            ->screenshot(true, "phase4-test-{$screen}-{$mode}-{$width}");

        expect($page->script('document.documentElement.scrollWidth <= window.innerWidth + 1'))->toBeTrue("{$uri} overflows horizontally at {$width}");
    }
})->with(['light', 'dark'])->with([1440, 768]);

test('a missed habit offers "Retomar con 2 minutos", with no missed-day count and never in the danger colour', function (string $mode) {
    [$user, $walk, $read] = p4bSeed();
    $this->actingAs($user);

    $page = visit('/habits');
    $page = $mode === 'dark' ? $page->inDarkMode() : $page->inLightMode();

    $page->assertSee('Retomar con 2 minutos')
        ->assertSee('Ponerme las zapatillas')
        ->assertDontSee('días perdidos')
        ->assertDontSee('fallaste')
        ->assertDontSee('recuperar')
        ->assertNoJavascriptErrors();

    $colours = $page->script(<<<'JS'
        Array.from(document.querySelectorAll('body *')).flatMap((el) => {
            const s = getComputedStyle(el);
            return [s.color, s.backgroundColor, s.borderTopColor, s.fill, s.stroke];
        })
    JS);

    foreach (P4B_DANGER as $danger) {
        expect($colours)->not->toContain($danger);
    }

    expect($page->script('document.body.innerText'))->not->toMatch('/\b\d+\s+(días|dias)\s+(sin|perdid|fallad)/iu');
})->with(['light', 'dark']);

test('the restart logs only the 2-minute version for today and the row turns shown-up', function () {
    [$user, $walk, $read] = p4bSeed();
    $this->actingAs($user);

    $page = visit('/habits');
    $page->script(<<<'JS'
        Array.from(document.querySelectorAll('button')).find((b) => b.textContent.includes('Retomar con 2 minutos: Abrir el libro')).click()
    JS);
    $page->assertNoJavascriptErrors();

    $page->wait(0.5);

    $day = $read->days()->where('entry_date', Habit::todayLocalDate()->toDateString())->first();

    expect($day)->not->toBeNull()
        ->and($day->two_minute_logged)->toBeTrue()
        ->and($day->completed)->toBeFalse()
        ->and($read->days()->count())->toBe(11);
});

test('the identity screen speaks in proportions, never a percentage or score', function () {
    [$user] = p4bSeed();
    $this->actingAs($user);

    $page = visit('/habits/identity');

    $page->assertSee('Soy alguien que se mueve')
        ->assertSee('Soy alguien que lee')
        ->assertNoJavascriptErrors();

    $text = (string) $page->script('document.body.innerText');

    expect($text)->toMatch('/\d+\s+de\s+\d+/u')
        ->and($text)->not->toMatch('/\d+\s*%/u')
        ->and(mb_strtolower($text))->not->toContain('puntos');
});

test('a level-up is only a suggestion until the owner accepts it; the streak is kept', function () {
    [$user, $walk] = p4bSeed();
    $this->actingAs($user);
    $streak = $walk->history()->streak()->current;

    $page = visit("/habits/{$walk->id}");
    $page->assertSee('Subir a 10 min')->assertNoJavascriptErrors();

    expect($walk->fresh()->level)->toBe(1);

    $page->click('Subir a 10 min')->wait(0.5);
    $page->assertNoJavascriptErrors();

    $walk->refresh();

    expect($walk->level)->toBe(2)
        ->and($walk->two_minute_version)->toBe('Salir a la puerta')
        ->and($walk->history()->streak()->current)->toBe($streak);
});
