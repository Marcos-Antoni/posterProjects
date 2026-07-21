<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('creates a user with a hashed password from the prompted answers', function () {
    $this->artisan('app:create-owner-user')
        ->expectsQuestion('Nombre', 'Marco Barrera')
        ->expectsQuestion('Correo electrónico', 'marco@example.com')
        ->expectsQuestion('Contraseña', 'super-secret-password')
        ->assertExitCode(0);

    $user = User::query()->where('email', 'marco@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Marco Barrera')
        ->and($user->password)->not->toBe('super-secret-password')
        ->and(Hash::check('super-secret-password', $user->password))->toBeTrue();
});

test('rejects an email that already belongs to another user', function () {
    User::factory()->create(['email' => 'marco@example.com']);

    // The command aborts as soon as the email validation fails, so the
    // password prompt is never reached.
    $this->artisan('app:create-owner-user')
        ->expectsQuestion('Nombre', 'Marco Barrera')
        ->expectsQuestion('Correo electrónico', 'marco@example.com')
        ->assertExitCode(1);

    expect(User::query()->where('email', 'marco@example.com')->count())->toBe(1);
});

test('rejects a password shorter than the minimum length', function () {
    $this->artisan('app:create-owner-user')
        ->expectsQuestion('Nombre', 'Marco Barrera')
        ->expectsQuestion('Correo electrónico', 'marco@example.com')
        ->expectsQuestion('Contraseña', 'short')
        ->assertExitCode(1);

    expect(User::query()->where('email', 'marco@example.com')->exists())->toBeFalse();
});
