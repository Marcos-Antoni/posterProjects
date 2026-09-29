<?php

use App\Enums\Appearance;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

function inertiaHeaders(): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ];
}

test('users have an appearance column that defaults to system', function () {
    expect(Schema::hasColumn('users', 'appearance'))->toBeTrue();

    $user = User::factory()->create();

    expect($user->fresh()->appearance)->toBe(Appearance::System)
        ->and((new User)->appearance)->toBe(Appearance::System);
});

test('guests are redirected to login from the appearance page and cannot update it', function () {
    $this->get('/settings/appearance')->assertRedirect('/login');
    $this->patch('/settings/appearance', ['appearance' => 'dark'])->assertRedirect('/login');
});

test('the appearance page renders the stored choice', function () {
    $user = User::factory()->create(['appearance' => Appearance::Dark]);

    $response = $this->actingAs($user)->get('/settings/appearance', inertiaHeaders());

    $response->assertOk()
        ->assertJsonPath('component', 'settings/appearance')
        ->assertJsonPath('props.appearance', 'dark');
});

test('choosing a theme persists it on the user', function (string $choice) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings/appearance')
        ->patch('/settings/appearance', ['appearance' => $choice])
        ->assertRedirect('/settings/appearance')
        ->assertSessionHasNoErrors();

    expect($user->fresh()->appearance)->toBe(Appearance::from($choice));
})->with(['light', 'dark', 'system']);

test('an unknown theme is rejected and nothing changes', function (mixed $choice) {
    $user = User::factory()->create(['appearance' => Appearance::Light]);

    $this->actingAs($user)
        ->from('/settings/appearance')
        ->patch('/settings/appearance', ['appearance' => $choice])
        ->assertSessionHasErrors('appearance');

    expect($user->fresh()->appearance)->toBe(Appearance::Light);
})->with(['hacker', '', null, 'DARK']);

test('the update only touches the appearance, never the profile or password', function () {
    $user = User::factory()->create(['name' => 'Marco', 'email' => 'marco@example.com']);
    $passwordHash = $user->password;

    $this->actingAs($user)->patch('/settings/appearance', [
        'appearance' => 'dark',
        'name' => 'Hacked',
        'email' => 'hacked@example.com',
        'password' => 'new-password-123',
    ]);

    $fresh = $user->fresh();

    expect($fresh->appearance)->toBe(Appearance::Dark)
        ->and($fresh->name)->toBe('Marco')
        ->and($fresh->email)->toBe('marco@example.com')
        ->and($fresh->password)->toBe($passwordHash);
});

test('for an authenticated user the stored preference wins over a stale cookie', function () {
    $user = User::factory()->create(['appearance' => Appearance::Dark]);

    $response = $this->actingAs($user)
        ->withUnencryptedCookie('appearance', 'light')
        ->get('/settings/appearance', inertiaHeaders());

    expect($response->json('props.appearance'))->toBe('dark');
});

test('every page renders dark from the first byte when the stored preference is dark', function (string $path) {
    $user = User::factory()->create(['appearance' => Appearance::Dark]);

    $html = $this->actingAs($user)->get($path)->assertOk()->getContent();

    expect($html)->toMatch('/<html[^>]*\bclass="dark"/')
        ->and($html)->toMatch('/<html[^>]*\bdata-appearance="dark"/');
})->with(['/settings/appearance', '/settings/mcp-token', '/settings/mobile-token', '/habits', '/objectives']);

test('a light preference renders without the dark class even when the browser cookie says dark', function () {
    $user = User::factory()->create(['appearance' => Appearance::Light]);

    $html = $this->actingAs($user)
        ->withUnencryptedCookie('appearance', 'dark')
        ->get('/settings/appearance')
        ->getContent();

    expect($html)->not->toMatch('/<html[^>]*\bclass="dark"/')
        ->and($html)->toMatch('/<html[^>]*\bdata-appearance="light"/');
});

test('a system preference is handed to the anti-flash script to resolve with the OS setting', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get('/settings/appearance')->getContent();

    expect($html)->toMatch('/<html[^>]*\bdata-appearance="system"/')
        ->and($html)->toContain('prefers-color-scheme: dark')
        ->and($html)->toContain('dataset.appearance');
});

test('saving the preference also sets the browser cookie so the login page matches after logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/appearance', ['appearance' => 'dark'])
        ->assertCookie('appearance', 'dark', encrypted: false);
});

test('the stored preference re-syncs a stale browser cookie on any page', function () {
    $user = User::factory()->create(['appearance' => Appearance::Dark]);

    $this->actingAs($user)
        ->withUnencryptedCookie('appearance', 'light')
        ->get('/settings/mcp-token')
        ->assertCookie('appearance', 'dark', encrypted: false);
});

test('the chosen theme survives logging out and logging in from another browser', function () {
    $user = User::factory()->create(['email' => 'marco@example.com']);

    $this->actingAs($user)->patch('/settings/appearance', ['appearance' => 'dark']);
    $this->post('/logout');
    $this->assertGuest();

    // "Another browser": no cookies at all, a fresh session.
    $this->flushSession();
    $this->app['auth']->forgetGuards();

    $this->post('/login', ['email' => 'marco@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($user);

    $html = $this->get('/objectives')->getContent();
    expect($html)->toMatch('/<html[^>]*\bclass="dark"/');

    $page = $this->get('/settings/appearance', inertiaHeaders());
    expect($page->json('props.appearance'))->toBe('dark');
});
