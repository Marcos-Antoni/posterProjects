<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password;

beforeEach(function () {
    $this->owner = User::factory()->create([
        'email' => 'marco@example.com',
        'password' => Hash::make('old-password-123'),
    ]);
});

test('the new password authenticates at the login screen and the old one does not', function () {
    $this->artisan('marcos:set-password', ['email' => 'marco@example.com'])
        ->expectsQuestion('Nueva contraseña', 'brand-new-password-456')
        ->expectsQuestion('Repetí la nueva contraseña', 'brand-new-password-456')
        ->assertExitCode(0);

    $this->post('/login', ['email' => 'marco@example.com', 'password' => 'old-password-123']);
    $this->assertGuest();

    $this->post('/login', ['email' => 'marco@example.com', 'password' => 'brand-new-password-456']);
    $this->assertAuthenticatedAs($this->owner);
});

test('stores a hash, never the plain text', function () {
    $this->artisan('marcos:set-password', ['email' => 'marco@example.com'])
        ->expectsQuestion('Nueva contraseña', 'brand-new-password-456')
        ->expectsQuestion('Repetí la nueva contraseña', 'brand-new-password-456')
        ->assertExitCode(0);

    $stored = $this->owner->fresh()->password;

    expect($stored)->not->toBe('brand-new-password-456')
        ->and(Hash::check('brand-new-password-456', $stored))->toBeTrue();
});

test('rotates the remember token so remembered sessions of the old password die', function () {
    $this->owner->forceFill(['remember_token' => 'old-remember-token'])->save();

    $this->artisan('marcos:set-password', ['email' => 'marco@example.com'])
        ->expectsQuestion('Nueva contraseña', 'brand-new-password-456')
        ->expectsQuestion('Repetí la nueva contraseña', 'brand-new-password-456')
        ->assertExitCode(0);

    expect($this->owner->fresh()->remember_token)->not->toBe('old-remember-token');
});

test('never echoes nor logs the password', function () {
    Log::spy();

    $this->artisan('marcos:set-password', ['email' => 'marco@example.com'])
        ->expectsQuestion('Nueva contraseña', 'brand-new-password-456')
        ->expectsQuestion('Repetí la nueva contraseña', 'brand-new-password-456')
        ->doesntExpectOutputToContain('brand-new-password-456')
        ->assertExitCode(0);

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('log');
});

test('fails without changing anything when both entries do not match', function () {
    $before = $this->owner->password;

    $this->artisan('marcos:set-password', ['email' => 'marco@example.com'])
        ->expectsQuestion('Nueva contraseña', 'brand-new-password-456')
        ->expectsQuestion('Repetí la nueva contraseña', 'brand-new-password-789')
        ->expectsOutputToContain('Las contraseñas no coinciden')
        ->doesntExpectOutputToContain('brand-new-password')
        ->assertExitCode(1);

    expect($this->owner->fresh()->password)->toBe($before);
});

test('fails without changing anything when the password breaks the default rules', function () {
    $before = $this->owner->password;

    $this->artisan('marcos:set-password', ['email' => 'marco@example.com'])
        ->expectsQuestion('Nueva contraseña', 'short')
        ->expectsQuestion('Repetí la nueva contraseña', 'short')
        ->expectsOutputToContain('La contraseña debe tener al menos 8 caracteres.')
        ->doesntExpectOutputToContain('short')
        ->assertExitCode(1);

    expect($this->owner->fresh()->password)->toBe($before);
});

test('validates with the application default password rules', function () {
    Password::defaults(fn () => Password::min(20));

    $before = $this->owner->password;

    $this->artisan('marcos:set-password', ['email' => 'marco@example.com'])
        ->expectsQuestion('Nueva contraseña', 'fifteen-chars-x')
        ->expectsQuestion('Repetí la nueva contraseña', 'fifteen-chars-x')
        ->assertExitCode(1);

    expect($this->owner->fresh()->password)->toBe($before);

    $this->artisan('marcos:set-password', ['email' => 'marco@example.com'])
        ->expectsQuestion('Nueva contraseña', 'a-password-of-twenty-plus')
        ->expectsQuestion('Repetí la nueva contraseña', 'a-password-of-twenty-plus')
        ->assertExitCode(0);

    expect(Hash::check('a-password-of-twenty-plus', $this->owner->fresh()->password))->toBeTrue();
});

test('fails for an unknown email without prompting for a password', function () {
    $this->artisan('marcos:set-password', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No existe un usuario con el correo nobody@example.com')
        ->assertExitCode(1);
});

test('the web ui exposes no route that changes the password or edits the profile', function () {
    $offending = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => ! str_starts_with($route->uri(), 'api/')
            && ! str_starts_with($route->uri(), 'mcp'))
        ->filter(fn ($route) => str_contains($route->uri(), 'password') || str_contains($route->uri(), 'profile'))
        ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        ->reject(fn ($route) => $route->getName() === 'password.confirm.store')
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($offending)->toBe([]);

    $this->actingAs($this->owner)->put('/settings/password', ['password' => 'x'])->assertNotFound();
    $this->actingAs($this->owner)->patch('/settings/profile', ['name' => 'x'])->assertNotFound();
});

test('ends every web session of that user and leaves other users signed in', function () {
    config(['session.driver' => 'database']);

    $other = User::factory()->create();

    foreach ([[$this->owner->id, 'owner-a'], [$this->owner->id, 'owner-b'], [$other->id, 'other']] as [$userId, $id]) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'payload' => 'x',
            'last_activity' => time(),
        ]);
    }

    $this->artisan('marcos:set-password', ['email' => 'marco@example.com'])
        ->expectsQuestion('Nueva contraseña', 'brand-new-password-456')
        ->expectsQuestion('Repetí la nueva contraseña', 'brand-new-password-456')
        ->expectsOutputToContain('Sesiones web cerradas: 2.')
        ->assertExitCode(0);

    expect(DB::table('sessions')->where('user_id', $this->owner->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'other')->exists())->toBeTrue();
});

test('a failed change leaves the sessions alone', function () {
    config(['session.driver' => 'database']);

    DB::table('sessions')->insert(['id' => 'owner-a', 'user_id' => $this->owner->id, 'payload' => 'x', 'last_activity' => time()]);

    $this->artisan('marcos:set-password', ['email' => 'marco@example.com'])
        ->expectsQuestion('Nueva contraseña', 'brand-new-password-456')
        ->expectsQuestion('Repetí la nueva contraseña', 'different-password-789')
        ->assertExitCode(1);

    expect(DB::table('sessions')->where('user_id', $this->owner->id)->count())->toBe(1);
});
