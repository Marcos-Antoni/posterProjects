<?php

use App\Enums\TokenName;
use App\Models\User;

/**
 * @return array<string, string>
 */
function apiBearerHeaders(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

test('a fresh login mints a mobile token, exposes the owner, and dies on logout', function () {
    $user = User::factory()->create();

    $login = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $login->assertOk();
    $token = $login->json('token');
    expect($token)->toBeString()->not->toBe('');

    $stored = $user->tokens()->where('name', TokenName::Mobile->value)->sole();
    expect($stored->abilities)->toBe([TokenName::Mobile->value]);

    $me = $this->getJson('/api/v1/user', apiBearerHeaders($token));
    $me->assertOk();
    expect($me->json('data'))->toBe([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ]);

    $logout = $this->postJson('/api/v1/logout', [], apiBearerHeaders($token));
    $logout->assertNoContent();

    // The sanctum guard caches the user it resolved on its first call and
    // this test keeps reusing the same in-memory app across requests, so
    // it must be dropped here to force re-authentication from the fresh
    // (now-revoked) bearer token — production serves every request from a
    // clean process and never hits this.
    $this->app['auth']->forgetGuards();

    $dead = $this->getJson('/api/v1/user', apiBearerHeaders($token));
    $dead->assertStatus(401);
    expect($dead->headers->get('WWW-Authenticate'))->toStartWith('Bearer');
});

test('a fresh login retires the previous mobile token, not the mcp token', function () {
    $user = User::factory()->create();
    $user->createToken(TokenName::Mcp->value, [TokenName::Mcp->value]);
    $previousMobile = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $login = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $login->assertOk();

    expect($user->tokens()->where('name', TokenName::Mobile->value)->count())->toBe(1)
        ->and($user->tokens()->where('name', TokenName::Mcp->value)->count())->toBe(1);

    $dead = $this->getJson('/api/v1/user', apiBearerHeaders($previousMobile));
    $dead->assertStatus(401);
});

test('wrong password is rejected with the shared spanish credentials message', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'Estas credenciales no coinciden con nuestros registros.');
    $response->assertJsonPath('errors.email.0', 'Estas credenciales no coinciden con nuestros registros.');
});

test('a request to a protected api route without a token gets a bearer challenge', function () {
    $response = $this->getJson('/api/v1/user');

    $response->assertStatus(401);
    expect($response->headers->get('WWW-Authenticate'))->toStartWith('Bearer');
});

test('the sixth login attempt within a minute is throttled', function () {
    $user = User::factory()->create();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertStatus(429);
    expect($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->json('message'))->toStartWith('Demasiados intentos de acceso.');
});
