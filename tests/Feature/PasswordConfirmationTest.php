<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // A stand-in for the future bridge/grant routes guarded by the same middleware.
    Route::middleware(['web', 'auth', 'password.confirm'])
        ->get('/__test/guarded', fn () => 'ran')
        ->name('test.guarded');
});

test('guests cannot reach the confirmation screen', function () {
    $this->get('/confirm-password')->assertRedirect('/login');
    $this->post('/confirm-password', ['password' => 'password'])->assertRedirect('/login');
});

test('the confirmation screen renders', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/confirm-password', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ])
        ->assertOk()
        ->assertJsonPath('component', 'auth/confirm-password');
});

test('a guarded action redirects to the confirmation screen and does not run', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/__test/guarded')->assertRedirect('/confirm-password');
});

test('the right password confirms and continues to the intended action', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/__test/guarded');

    $this->post('/confirm-password', ['password' => 'password'])
        ->assertRedirect('/__test/guarded')
        ->assertSessionHas('auth.password_confirmed_at');

    $this->get('/__test/guarded')->assertOk()->assertSee('ran');
});

test('a wrong password is rejected with a Spanish message and confirms nothing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/confirm-password')
        ->post('/confirm-password', ['password' => 'wrong-password'])
        ->assertRedirect('/confirm-password')
        ->assertSessionHasErrors(['password' => 'La contraseña no coincide. Probá de nuevo.'])
        ->assertSessionMissing('auth.password_confirmed_at');
});

test('a confirmation lasts 15 minutes', function () {
    expect(config('auth.password_timeout'))->toBe(900);

    $user = User::factory()->create();

    $this->actingAs($user)->post('/confirm-password', ['password' => 'password']);

    $this->travel(14)->minutes();
    $this->get('/__test/guarded')->assertOk();

    $this->travel(2)->minutes();
    $this->get('/__test/guarded')->assertRedirect('/confirm-password');
});
