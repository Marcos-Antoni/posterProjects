<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

test('login page renders as a full page with the built Vite assets', function () {
    // Unlike the Inertia-headers test below, this hits the real Blade shell
    // (`resources/views/app.blade.php`), which requires the compiled
    // `resources/js/pages/auth/login.tsx` asset to exist in the Vite
    // manifest. This is the regression test the T-8.3 plan asked for.
    $response = $this->get('/login');

    $response->assertOk();
});

test('login screen can be rendered', function () {
    // Requesting with the Inertia headers returns the raw page payload as
    // JSON instead of the full Blade shell, so this test verifies the
    // route/controller contract without depending on the `login.tsx` page
    // component, which ships separately in T-8.3 (the shell's `@vite` tag
    // requires the built asset to exist).
    $response = $this->get('/login', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'auth/login');
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    // Redirects straight to /projects (not /) so a fresh login doesn't need
    // a second hop through the root's own auth-branching redirect.
    $response->assertRedirect(route('projects.index', absolute: false));
});

test('users cannot authenticate with an invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('login is rate limited after too many failed attempts', function () {
    $user = User::factory()->create();

    RateLimiter::clear(mb_strtolower($user->email).'|127.0.0.1');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('authenticated users are redirected away from the login screen', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/login');

    $response->assertRedirect();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/login');
});

test('guests cannot logout', function () {
    $response = $this->post('/logout');

    $response->assertRedirect('/login');
    $this->assertGuest();
});
