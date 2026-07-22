<?php

use App\Models\User;

test('appearance shared prop defaults to system for guests without a cookie', function () {
    $response = $this->get('/login', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    expect($response->json('props.appearance'))->toBe('system');
});

test('appearance shared prop defaults to system for authenticated users without a cookie', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/projects', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    expect($response->json('props.appearance'))->toBe('system');
});

test('appearance shared prop reflects the appearance cookie value', function () {
    $response = $this->withUnencryptedCookie('appearance', 'dark')->get('/login', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    expect($response->json('props.appearance'))->toBe('dark');
});

test('the html root element gets the dark class when the appearance cookie is dark', function () {
    $response = $this->withUnencryptedCookie('appearance', 'dark')->get('/login');

    $response->assertOk();
    $response->assertSee('<html lang="en" class="dark">', false);
});

test('the html root element has no dark class when the appearance cookie is light', function () {
    $response = $this->withUnencryptedCookie('appearance', 'light')->get('/login');

    $response->assertOk();
    $response->assertSee('<html lang="en" class="">', false);
});

test('appearance shared prop falls back to system for an invalid cookie value', function () {
    $response = $this->withUnencryptedCookie('appearance', 'hacker')->get('/login', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    expect($response->json('props.appearance'))->toBe('system');
});

test('appearance shared prop falls back to system for an empty cookie value', function () {
    $response = $this->withUnencryptedCookie('appearance', '')->get('/login', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    expect($response->json('props.appearance'))->toBe('system');
});

test('the anti-fouc inline script is rendered in the head before the vite asset tags', function () {
    $response = $this->get('/login');

    $response->assertOk();

    $html = $response->getContent();
    $scriptPosition = mb_strpos($html, 'id="appearance-script"');
    $vitePosition = mb_strpos($html, '/build/assets/');

    expect($scriptPosition)->not->toBeFalse()
        ->and($vitePosition)->not->toBeFalse()
        ->and($scriptPosition)->toBeLessThan($vitePosition);
});
