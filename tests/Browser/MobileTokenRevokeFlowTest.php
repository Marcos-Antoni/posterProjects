<?php

use App\Enums\TokenName;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Real login (through the form, not `actingAs`) followed by the sidebar
 * dropdown → mobile token settings flow: an active `mobile` token is
 * revoked through the explicit confirmation dialog, the page settles on
 * the empty state, and the dead token then fails over HTTP.
 */
test('a user logs in through the form, opens the mobile token settings from the sidebar, confirms, and revokes the token', function () {
    // The factory's default password is 'password' (see UserFactory).
    $user = User::factory()->create(['email' => 'pilot@example.com']);
    $mobileToken = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value]);
    $plainToken = $mobileToken->plainTextToken;

    $page = visit('/login');

    $page->assertSee('Iniciar sesión')
        ->fill('email', 'pilot@example.com')
        ->fill('password', 'password')
        ->press('Ingresar')
        ->assertPathIs('/projects')
        ->assertNoJavascriptErrors();

    $page->click($user->name)
        ->assertSee('Token móvil')
        ->click('Token móvil')
        ->assertPathIs('/settings/mobile-token')
        ->assertSee('Token activo')
        ->assertNoJavascriptErrors();

    // Open the confirmation dialog, then confirm inside it — the
    // destructive action never fires from a single click (see
    // mobile-token.tsx and spec requirement R8).
    $page->click('Revocar token')
        ->assertSee('¿Revocar el token móvil?')
        ->assertNoJavascriptErrors();

    $page->click('Revocar token')
        ->assertSee('Todavía no hay un token móvil activo.')
        ->assertNoJavascriptErrors();

    expect(PersonalAccessToken::query()->where('name', TokenName::Mobile->value)->count())->toBe(0);

    $dead = $this->getJson('/api/v1/user', ['Authorization' => "Bearer {$plainToken}"]);
    $dead->assertStatus(401);
});
