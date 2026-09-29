<?php

use App\Actions\Support\Actor;
use App\Enums\TokenName;
use App\Models\Capture;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Marcos OS helpers (prefixed `mos` so they never collide with the helpers of
 * the independent acceptance suites).
 *
 * A complete objective payload: every one of the Control 5 points plus a
 * control map with one entry per zone.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mosObjectiveData(array $overrides = []): array
{
    return array_replace([
        'key' => 'WEB',
        'title' => 'Marcos OS web v1',
        'identity_statement' => 'Soy alguien que construye cada día',
        'outcome' => 'La pantalla Ahora y la captura rápida funcionan y las uso todos los días.',
        'deadline' => '2026-11-29',
        'metric' => ['name' => 'Pantallas en uso diario', 'target' => 2, 'current' => 0],
        'risks' => ['Me meto a rediseñar todo antes de tener Ahora andando.'],
        'contingency' => 'Cuando me descubra rediseñando, entonces lo anoto en Captura y vuelvo a la tarea activa.',
        'control_map' => [
            ['zone' => 'mine', 'text' => 'Escribir el spec de Ahora'],
            ['zone' => 'influence', 'text' => 'Que Coolify despliegue sin fallar'],
            ['zone' => 'outside', 'text' => 'Cambios en la API de Claude'],
        ],
    ], $overrides);
}

/**
 * Run a callback expected to throw a ValidationException and return its
 * errors, keyed by field.
 *
 * @return array<string, list<string>>
 */
function mosErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Expected a ValidationException.');
}

/**
 * The owner-web actor of the objective an item, plan or objective belongs to
 * (a capture's own `user_id`, phase 7).
 */
function mosOwner(Item|Plan|Objective|Capture $model): Actor
{
    if ($model instanceof Capture) {
        return Actor::ownerWeb($model->user);
    }

    $objective = $model instanceof Objective ? $model : $model->objective;

    return Actor::ownerWeb($objective->user);
}

/**
 * Bearer headers for a fresh `mobile` token of the user.
 *
 * @return array<string, string>
 */
function mosMobileHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken];
}

/**
 * The query count of one request, with lazy loading forbidden. Guards are
 * forgotten first so a request with another user's token is not served by
 * the user a previous request of the same test authenticated.
 */
function mosQueryCount(Closure $request): int
{
    app('auth')->forgetGuards();
    Model::preventLazyLoading();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $request();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    Model::preventLazyLoading(false);

    return $count;
}
