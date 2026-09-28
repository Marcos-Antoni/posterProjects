<?php

/*
| Phase 3 acceptance (independent tester) — "Exactly One Active Task At A
| Time" under real concurrency (design D6): two separate PHP processes, each
| with its own database connection, run StartItem on two different items of
| the same owner at the same instant. Both requests must succeed (no unique
| violation leaking to the owner) and exactly one item must end up active
| with exactly one open focus session. The data is committed through a side
| connection (outside RefreshDatabase's transaction) and removed afterwards.
*/

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

const P3C_SIDE = 'p3_concurrency_side';

function p3cSide(): Connection
{
    if (config('database.connections.'.P3C_SIDE) === null) {
        config(['database.connections.'.P3C_SIDE => config('database.connections.'.config('database.default'))]);
    }

    return DB::connection(P3C_SIDE);
}

/**
 * @return array{user: int, items: list<int>}
 */
function p3cSeed(int $items): array
{
    $side = p3cSide();
    $suffix = bin2hex(random_bytes(3));
    $user = $side->table('users')->insertGetId([
        'name' => 'Concurrencia', 'email' => "p3c-{$suffix}@example.test", 'password' => bcrypt('x'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $objective = $side->table('objectives')->insertGetId([
        'user_id' => $user, 'key' => 'C'.strtoupper($suffix), 'title' => 'Concurrencia', 'state' => 'active',
        'position' => 0, 'next_item_number' => $items + 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $plan = $side->table('plans')->insertGetId([
        'objective_id' => $objective, 'title' => 'Plan', 'state' => 'active', 'position' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $ids = [];
    foreach (range(1, $items) as $number) {
        $ids[] = $side->table('items')->insertGetId([
            'objective_id' => $objective, 'plan_id' => $plan, 'number' => $number, 'kind' => 'task',
            'title' => "Tarea {$number}", 'two_minute_version' => 'abrir algo', 'is_active' => false,
            'position' => $number - 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return ['user' => $user, 'items' => $ids];
}

function p3cCleanup(int $user): void
{
    $side = p3cSide();
    $items = $side->table('items')->where('user_id', $user)->pluck('id');
    $side->table('focus_sessions')->whereIn('item_id', $items)->delete();
    $side->table('item_two_minute_history')->whereIn('item_id', $items)->delete();
    $side->table('items')->whereIn('id', $items)->delete();
    $objectives = $side->table('objectives')->where('user_id', $user)->pluck('id');
    $side->table('plans')->whereIn('objective_id', $objectives)->delete();
    $side->table('objectives')->whereIn('id', $objectives)->delete();
    $side->table('users')->where('id', $user)->delete();
}

function p3cWorkerScript(): string
{
    $path = sys_get_temp_dir().'/p3c-start-worker-'.getmypid().'.php';
    $base = base_path();

    file_put_contents($path, <<<PHP
        <?php
        require '{$base}/vendor/autoload.php';
        \$app = require '{$base}/bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        \$user = App\\Models\\User::query()->findOrFail((int) \$argv[1]);
        \$item = App\\Models\\Item::query()->findOrFail((int) \$argv[2]);
        \$at = (float) \$argv[3];
        while (microtime(true) < \$at) { usleep(100); }
        try {
            app(App\\Actions\\Items\\StartItem::class)(App\\Actions\\Support\\Actor::ownerWeb(\$user), \$item);
            echo 'ok';
        } catch (Throwable \$e) {
            echo get_class(\$e).': '.\$e->getMessage();
            exit(1);
        }
        PHP);

    return $path;
}

function p3cProcess(string $script, int $user, int $item, float $at): Process
{
    $connection = config('database.connections.'.config('database.default'));

    return new Process([PHP_BINARY, $script, (string) $user, (string) $item, sprintf('%.6F', $at)], base_path(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => (string) $connection['database'],
        'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ], null, 60);
}

test('two simultaneous starts of different items leave exactly one active item and one open focus session', function () {
    $seed = p3cSeed(2);
    $script = p3cWorkerScript();

    try {
        foreach (range(1, 6) as $round) {
            $at = microtime(true) + 2.5;
            $first = p3cProcess($script, $seed['user'], $seed['items'][$round % 2], $at);
            $second = p3cProcess($script, $seed['user'], $seed['items'][($round + 1) % 2], $at);
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();

            expect([$first->getOutput(), $second->getOutput()])->toBe(['ok', 'ok'], "round {$round}: ".$first->getOutput().' | '.$second->getOutput());

            $side = p3cSide();
            $active = $side->table('items')->where('user_id', $seed['user'])->where('is_active', true)->pluck('id');
            $open = $side->table('focus_sessions')->whereIn('item_id', $seed['items'])->whereNull('ended_at')->pluck('item_id');

            expect($active)->toHaveCount(1, "round {$round}: active items")
                ->and($open->all())->toBe($active->all(), "round {$round}: the only open session belongs to the active item");
        }
    } finally {
        @unlink($script);
        p3cCleanup($seed['user']);
    }
});

test('a start waits for a concurrent start of the same owner instead of racing it', function () {
    $seed = p3cSeed(1);
    $script = p3cWorkerScript();
    $side = p3cSide();

    try {
        $side->beginTransaction();
        $side->table('users')->where('id', $seed['user'])->lockForUpdate()->first();

        $worker = p3cProcess($script, $seed['user'], $seed['items'][0], microtime(true));
        $worker->start();
        usleep(3_000_000);

        $blocked = $worker->isRunning();
        $side->commit();
        $worker->wait();

        expect($blocked)->toBeTrue('StartItem must serialize on the owner (it finished while another start held the owner)')
            ->and($worker->getOutput())->toBe('ok');
    } finally {
        if ($side->transactionLevel() > 0) {
            $side->rollBack();
        }
        @unlink($script);
        p3cCleanup($seed['user']);
    }
});
