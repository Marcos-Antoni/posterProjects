<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

test('guests are redirected to login when visiting the mcp token page', function () {
    $response = $this->get('/settings/mcp-token');

    $response->assertRedirect('/login');
});

test('the page reports no token before one is generated', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/settings/mcp-token', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'settings/mcp-token');
    $response->assertJsonPath('props.token', null);
    $response->assertJsonPath('props.flash.plainMcpToken', null);
});

test('generating a token stores a single pat and flashes the plain text once', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/settings/mcp-token');

    $response->assertRedirect('/settings/mcp-token');
    $response->assertSessionHas('plainMcpToken');

    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->first()->name)->toBe('mcp');

    // The visit right after the redirect carries the one-shot flash...
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ];

    $followUp = $this->get('/settings/mcp-token', $headers);
    $followUp->assertOk();

    $plain = $followUp->json('props.flash.plainMcpToken');
    expect($plain)->toBeString()->not->toBe('');

    // ...and any later visit never sees the plain token again.
    $later = $this->get('/settings/mcp-token', $headers);
    $later->assertOk();
    $later->assertJsonPath('props.flash.plainMcpToken', null);
    $later->assertJsonPath('props.token.created_at', fn ($value) => is_string($value));
});

test('regenerating kills the previous token immediately', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/mcp-token');
    $original = $user->tokens()->firstOrFail();

    $this->actingAs($user)->post('/settings/mcp-token');

    expect($user->tokens()->count())->toBe(1)
        ->and(PersonalAccessToken::query()->find($original->id))->toBeNull();
});

test('the plain token never leaks into the persistent token prop', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/mcp-token');

    $response = $this->get('/settings/mcp-token', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();

    // The persistent prop only carries metadata — never the secret.
    expect($response->json('props.token'))
        ->toHaveKeys(['created_at', 'last_used_at'])
        ->not->toHaveKey('token');
});
