<?php

/*
| Phase 1 acceptance (independent tester) — spec `auth` (Phase-1 parts):
| appearance preference, CLI-only password change, password confirmation
| guard with a 15-minute window, and the absence of profile/password web
| routes. Derived from the spec, not from the implementation.
*/

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

function p1aHtmlTag(string $html): string
{
    preg_match('/<html[^>]*>/', $html, $m);

    return $m[0] ?? '';
}

describe('appearance preference', function () {
    test('a user row created without a theme defaults to "system" at the database level', function () {
        $id = DB::table('users')->insertGetId([
            'name' => 'Raw', 'email' => 'raw-p1a@example.test', 'password' => Hash::make('x'),
        ]);

        expect(DB::table('users')->where('id', $id)->value('appearance'))->toBe('system');
    });

    test('a new user renders following the OS: no forced dark class, "system" marker, resolver script before any stylesheet', function () {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/settings/appearance')->assertOk()->getContent();
        $tag = p1aHtmlTag($html);

        expect($tag)->toContain('data-appearance="system"')
            ->not->toMatch('/class="[^"]*\bdark\b/');

        $scriptAt = strpos($html, 'prefers-color-scheme');
        $firstCss = min(array_filter([strpos($html, 'rel="stylesheet"'), strpos($html, '.css'), strpos($html, 'type="module"')], fn ($p) => $p !== false) ?: [PHP_INT_MAX]);

        expect($scriptAt)->not->toBeFalse()
            ->and($scriptAt)->toBeLessThan($firstCss);
    });

    test('a stored "oscuro" is rendered dark by the server on the very first HTML (no flash), on several pages', function (string $uri) {
        $user = User::factory()->create(['appearance' => 'dark']);

        $tag = p1aHtmlTag($this->actingAs($user)->get($uri)->assertOk()->getContent());

        expect($tag)->toMatch('/class="[^"]*\bdark\b/')
            ->toContain('data-appearance="dark"');
    })->with(['/settings/appearance', '/settings/mcp-token', '/settings/mobile-token', '/objectives']);

    test('a stored "claro" wins over a stale guest cookie saying dark', function () {
        $user = User::factory()->create(['appearance' => 'light']);

        $tag = p1aHtmlTag($this->actingAs($user)->withUnencryptedCookie('appearance', 'dark')->get('/settings/appearance')->getContent());

        expect($tag)->toContain('data-appearance="light"')->not->toMatch('/class="[^"]*\bdark\b/');
    });

    test('the choice is per user: two users keep their own theme', function () {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($a)->patch('/settings/appearance', ['appearance' => 'dark'])->assertSessionHasNoErrors();
        $this->actingAs($b)->patch('/settings/appearance', ['appearance' => 'light'])->assertSessionHasNoErrors();

        expect($a->fresh()->appearance->value)->toBe('dark')
            ->and($b->fresh()->appearance->value)->toBe('light');
    });

    test('"oscuro" survives logout and a login from another browser, and the selector shows "oscuro"', function () {
        $user = User::factory()->create(['email' => 'p1a-dark@example.test', 'password' => Hash::make('secret-pass-1')]);

        $this->actingAs($user)->patch('/settings/appearance', ['appearance' => 'dark']);
        $this->post('/logout');
        $this->assertGuest();

        // "Another browser": drop every cookie / session of this client.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->post('/login', ['email' => 'p1a-dark@example.test', 'password' => 'secret-pass-1']);
        $this->assertAuthenticatedAs($user);

        $response = $this->get('/settings/appearance');

        expect(p1aHtmlTag($response->getContent()))->toMatch('/class="[^"]*\bdark\b/');
        $response->assertInertia(fn (Assert $page) => $page->component('settings/appearance')->where('appearance', 'dark'));
    });

    test('the page offers exactly the three options and rejects anything else without changing the stored value', function () {
        $user = User::factory()->create(['appearance' => 'light']);

        foreach (['blue', '', 'DARK', null] as $bad) {
            $this->actingAs($user)->patch('/settings/appearance', ['appearance' => $bad])->assertSessionHasErrors('appearance');
        }

        foreach (['light', 'dark', 'system'] as $ok) {
            $this->actingAs($user)->patch('/settings/appearance', ['appearance' => $ok])->assertSessionHasNoErrors();
            expect($user->fresh()->appearance->value)->toBe($ok);
        }
    });

    test('the appearance endpoint cannot be used to edit name, email or password', function () {
        $user = User::factory()->create(['name' => 'Orig', 'email' => 'orig-p1a@example.test', 'password' => Hash::make('orig-pass-1')]);

        $this->actingAs($user)->patch('/settings/appearance', [
            'appearance' => 'dark', 'name' => 'Hacked', 'email' => 'hacked@example.test', 'password' => 'hacked-pass',
        ]);

        $fresh = $user->fresh();
        expect($fresh->name)->toBe('Orig')
            ->and($fresh->email)->toBe('orig-p1a@example.test')
            ->and(Hash::check('orig-pass-1', $fresh->password))->toBeTrue();
    });

    test('guests cannot read or change the appearance setting', function () {
        $this->get('/settings/appearance')->assertRedirect('/login');
        $this->patch('/settings/appearance', ['appearance' => 'dark'])->assertRedirect('/login');
    });
});

describe('no profile or password editing on the web', function () {
    test('no registered web route edits the profile or the password', function () {
        $offending = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route) {
                $uri = $route->uri();
                $name = (string) $route->getName();

                if (in_array($name, ['password.confirm', 'password.confirm.store'], true)) {
                    return false;
                }

                return preg_match('/profile|password|register|forgot|reset/i', $uri.' '.$name) === 1;
            })
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()->all();

        expect($offending)->toBe([]);
    });

    test('classic password/profile endpoints do not exist', function (string $method, string $uri) {
        $user = User::factory()->create();

        $status = $this->actingAs($user)->call($method, $uri, ['password' => 'x', 'password_confirmation' => 'x'])->status();

        expect($status)->toBeIn([404, 405]);
    })->with([
        ['GET', '/settings/profile'],
        ['PATCH', '/settings/profile'],
        ['GET', '/settings/password'],
        ['PUT', '/settings/password'],
        ['GET', '/forgot-password'],
        ['POST', '/forgot-password'],
        ['POST', '/reset-password'],
        ['GET', '/register'],
    ]);
});

describe('marcos:set-password', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create(['email' => 'p1a-cli@example.test', 'password' => Hash::make('old-pass-p1a-1')]);
    });

    test('changes the password: new one logs in, old one does not', function () {
        $this->artisan('marcos:set-password', ['email' => 'p1a-cli@example.test'])
            ->expectsQuestion('Nueva contraseña', 'New-pass-p1a-2!')
            ->expectsQuestion('Repetí la nueva contraseña', 'New-pass-p1a-2!')
            ->assertExitCode(0);

        $this->post('/login', ['email' => 'p1a-cli@example.test', 'password' => 'old-pass-p1a-1']);
        $this->assertGuest();

        $this->post('/login', ['email' => 'p1a-cli@example.test', 'password' => 'New-pass-p1a-2!']);
        $this->assertAuthenticatedAs($this->owner);
    });

    test('two different entries change nothing and exit non-zero', function () {
        $this->artisan('marcos:set-password', ['email' => 'p1a-cli@example.test'])
            ->expectsQuestion('Nueva contraseña', 'New-pass-p1a-2!')
            ->expectsQuestion('Repetí la nueva contraseña', 'New-pass-p1a-3!')
            ->assertExitCode(1);

        expect(Hash::check('old-pass-p1a-1', $this->owner->fresh()->password))->toBeTrue();
    });

    test('an unknown email exits non-zero and touches nobody', function () {
        $before = $this->owner->fresh()->password;

        $this->artisan('marcos:set-password', ['email' => 'nobody-p1a@example.test'])->assertExitCode(1);

        expect($this->owner->fresh()->password)->toBe($before);
    });

    test('the default password rules apply (production rules reject a weak password)', function () {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('marcos:set-password', ['email' => 'p1a-cli@example.test'])
            ->expectsQuestion('Nueva contraseña', 'short')
            ->expectsQuestion('Repetí la nueva contraseña', 'short')
            ->assertExitCode(1);

        expect(Hash::check('old-pass-p1a-1', $this->owner->fresh()->password))->toBeTrue();
    });

    test('the password is never echoed, logged or accepted as an argument/option', function () {
        Log::spy();

        $this->artisan('marcos:set-password', ['email' => 'p1a-cli@example.test'])
            ->expectsQuestion('Nueva contraseña', 'Very-unique-p1a-9!')
            ->expectsQuestion('Repetí la nueva contraseña', 'Very-unique-p1a-9!')
            ->doesntExpectOutputToContain('Very-unique-p1a-9!')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('info', [Mockery::on(fn ($m) => str_contains((string) $m, 'Very-unique'))]);

        $definition = Artisan::all()['marcos:set-password']->getDefinition();
        expect(array_values(array_diff(array_keys($definition->getArguments()), ['command'])))->toBe(['email'])
            ->and(collect($definition->getOptions())->keys()->filter(fn ($o) => str_contains($o, 'pass'))->all())->toBe([]);
    });
});

describe('password confirmation guard (15 minutes)', function () {
    beforeEach(function () {
        Route::middleware(['web', 'auth', 'password.confirm'])
            ->post('/__p1a/guarded', fn () => response('RAN'))
            ->name('p1a.guarded');
        Route::middleware(['web', 'auth', 'password.confirm'])
            ->get('/__p1a/guarded', fn () => response('RAN'));

        $this->owner = User::factory()->create(['password' => Hash::make('confirm-pass-p1a')]);
    });

    test('without a confirmation a guarded action redirects to the confirmation screen and does not run', function () {
        $this->actingAs($this->owner)->post('/__p1a/guarded')
            ->assertRedirect(route('password.confirm'))
            ->assertDontSee('RAN');
    });

    test('the confirmation screen renders for the owner and requires authentication', function () {
        $this->actingAs($this->owner)->get('/confirm-password')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/confirm-password'));

        auth()->logout();
        $this->flushSession();
        $this->get('/confirm-password')->assertRedirect('/login');
    });

    test('a wrong password does not confirm', function () {
        $this->actingAs($this->owner)->post('/confirm-password', ['password' => 'nope'])->assertSessionHasErrors('password');

        $this->actingAs($this->owner)->post('/__p1a/guarded')->assertRedirect(route('password.confirm'));
    });

    test('the right password confirms, returns to the intended action, and the window lasts 15 minutes', function () {
        $this->actingAs($this->owner)->get('/__p1a/guarded')->assertRedirect(route('password.confirm'));

        $this->post('/confirm-password', ['password' => 'confirm-pass-p1a'])->assertRedirect('/__p1a/guarded');

        $this->get('/__p1a/guarded')->assertOk()->assertSee('RAN');

        $this->travel(14)->minutes();
        $this->post('/__p1a/guarded')->assertOk();

        $this->travel(2)->minutes(); // 16 minutes after confirming
        $this->post('/__p1a/guarded')->assertRedirect(route('password.confirm'));
    });

    test('a session confirmed 30 minutes ago must reconfirm', function () {
        $this->actingAs($this->owner)
            ->withSession(['auth.password_confirmed_at' => now()->subMinutes(30)->unix()])
            ->post('/__p1a/guarded')
            ->assertRedirect(route('password.confirm'));
    });

    test('confirmation attempts are throttled', function () {
        $this->actingAs($this->owner);

        foreach (range(1, 6) as $_) {
            $this->post('/confirm-password', ['password' => 'nope']);
        }

        $this->post('/confirm-password', ['password' => 'nope'])->assertStatus(429);
    });
});

describe('login screen (Phase-1 visible parts)', function () {
    test('renders as a full page with built assets and an invalid password does not authenticate', function () {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/login'));

        $user = User::factory()->create(['password' => Hash::make('right-p1a')]);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors();
        $this->assertGuest();
    });

    test('guests cannot log out', function () {
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    });
});

describe('fix round: login copy on both channels', function () {
    test('web login failure uses the mockup-01 copy', function () {
        $user = User::factory()->create();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'bad'])
            ->assertSessionHasErrors(['email' => 'El correo o la contraseña no coinciden.']);
        $this->assertGuest();
    });

    test('api v1 login failure keeps its pinned contract message and 422', function () {
        $user = User::factory()->create();

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'bad'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Estas credenciales no coinciden con nuestros registros.');
    });
});

describe('fix round: set-password closes web sessions', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create(['email' => 'p1a-sess@example.test', 'password' => Hash::make('old-pass-p1a-1')]);
        $this->other = User::factory()->create();

        foreach ([['s-own-1', $this->owner->id], ['s-own-2', $this->owner->id], ['s-other', $this->other->id], ['s-guest', null]] as [$id, $uid]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $uid, 'payload' => 'x', 'last_activity' => time()]);
        }
    });

    test('with the database driver it deletes only that user\'s sessions', function () {
        config(['session.driver' => 'database']);

        $this->artisan('marcos:set-password', ['email' => 'p1a-sess@example.test'])
            ->expectsQuestion('Nueva contraseña', 'New-pass-p1a-2!')
            ->expectsQuestion('Repetí la nueva contraseña', 'New-pass-p1a-2!')
            ->assertExitCode(0);

        expect(DB::table('sessions')->where('user_id', $this->owner->id)->count())->toBe(0)
            ->and(DB::table('sessions')->pluck('id')->sort()->values()->all())->toBe(['s-guest', 's-other']);
    });

    test('a failed change (mismatch) closes no session', function () {
        config(['session.driver' => 'database']);

        $this->artisan('marcos:set-password', ['email' => 'p1a-sess@example.test'])
            ->expectsQuestion('Nueva contraseña', 'New-pass-p1a-2!')
            ->expectsQuestion('Repetí la nueva contraseña', 'Other-pass-p1a-3!')
            ->assertExitCode(1);

        expect(DB::table('sessions')->count())->toBe(4);
    });

    test('with another driver it warns and still changes the password', function () {
        config(['session.driver' => 'file']);

        $this->artisan('marcos:set-password', ['email' => 'p1a-sess@example.test'])
            ->expectsQuestion('Nueva contraseña', 'New-pass-p1a-2!')
            ->expectsQuestion('Repetí la nueva contraseña', 'New-pass-p1a-2!')
            ->expectsOutputToContain('no se pueden cerrar')
            ->assertExitCode(0);

        expect(Hash::check('New-pass-p1a-2!', $this->owner->fresh()->password))->toBeTrue()
            ->and(DB::table('sessions')->count())->toBe(4);
    });
});
