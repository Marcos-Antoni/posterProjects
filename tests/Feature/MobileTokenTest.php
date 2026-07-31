<?php

use App\Enums\TokenName;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

test('guests are redirected to login when visiting the mobile token page', function () {
    $response = $this->get('/settings/mobile-token');

    $response->assertRedirect('/login');
});

test('the page reports no token before one is minted', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/settings/mobile-token', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath('component', 'settings/mobile-token');
    $response->assertJsonPath('props.token', null);
});

test('the page reports metadata for an active mobile token, scoped away from a coexisting mcp token', function () {
    $user = User::factory()->create();
    $user->createToken(TokenName::Mcp->value, [TokenName::Mcp->value]);
    $mobileToken = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value]);

    $response = $this->actingAs($user)->get('/settings/mobile-token', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
    ]);

    $response->assertOk();
    $response->assertJsonPath(
        'props.token.created_at',
        $mobileToken->accessToken->created_at->toISOString()
    );
});

test('revoking deletes only the mobile token and leaves a coexisting mcp token intact', function () {
    $user = User::factory()->create();
    $mcpToken = $user->createToken(TokenName::Mcp->value, [TokenName::Mcp->value]);
    $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value]);

    $response = $this->actingAs($user)->delete('/settings/mobile-token');

    $response->assertRedirect('/settings/mobile-token');

    expect($user->tokens()->where('name', TokenName::Mobile->value)->count())->toBe(0);

    $survivingMcp = PersonalAccessToken::query()->find($mcpToken->accessToken->id);
    expect($survivingMcp)->not->toBeNull()
        ->and($survivingMcp->name)->toBe(TokenName::Mcp->value);
});

test('the revoked mobile token fails over http on the next api request', function () {
    $user = User::factory()->create();
    $mobileToken = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value]);
    $plainToken = $mobileToken->plainTextToken;

    $this->actingAs($user)->delete('/settings/mobile-token');

    // Sanctum's guard checks `config('sanctum.guard', 'web')` *before*
    // the bearer token (see `Laravel\Sanctum\Guard::__invoke`). The
    // `actingAs()` call above sets the web session guard's in-memory
    // user directly (no real session write) and that guard instance
    // stays cached in the container for the rest of this test method,
    // so without this reset the next request would authenticate via
    // the *web* guard fallback and never actually check the deleted
    // bearer token. Same root cause as `ApiAuthTest`'s identical reset;
    // production always serves each request from a fresh process.
    $this->app['auth']->forgetGuards();

    $dead = $this->getJson('/api/v1/user', ['Authorization' => "Bearer {$plainToken}"]);

    $dead->assertStatus(401);
});
