<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Real login (through the form, not `actingAs`) followed by the appbar's
 * Ajustes link → MCP token settings flow: generate, then regenerate.
 */
test('a user logs in through the form, opens the mcp token settings from the appbar, and generates then regenerates the token', function () {
    // The factory's default password is 'password' (see UserFactory).
    $user = User::factory()->create(['email' => 'pilot@example.com']);

    $page = visit('/login');

    $page->assertSee('Entrar')
        ->fill('email', 'pilot@example.com')
        ->fill('password', 'password')
        ->press('button[type="submit"]')
        ->assertPathIs('/now')
        ->assertNoJavascriptErrors();

    $page->click('a[aria-label="Ajustes"]')
        ->assertSee('Token MCP')
        ->click('Token MCP')
        ->assertPathIs('/settings/mcp-token')
        ->assertSee('Generar token')
        ->assertNoJavascriptErrors();

    // Generate the first token. The "Copiar" button relies on
    // `navigator.clipboard.writeText`, which headless Chromium rejects
    // without an interactively-granted permission — clicking it throws
    // an unhandled promise rejection there, so this only asserts the
    // one-shot notice and the readonly token input instead of exercising
    // the copy button itself.
    $page->click('Generar token')
        ->assertSee('Copiá tu token ahora')
        ->assertPresent('input[readonly]')
        ->assertValueIsNot('input[readonly]', '')
        ->assertNoJavascriptErrors();

    expect(PersonalAccessToken::query()->count())->toBe(1);
    $firstTokenId = PersonalAccessToken::query()->sole()->id;

    // Regenerate: a new plain token is flashed and the old record is gone.
    $page->click('Regenerar token')
        ->assertSee('Copiá tu token ahora')
        ->assertSee('Al regenerar, este token queda revocado')
        ->assertNoJavascriptErrors();

    expect(PersonalAccessToken::query()->count())->toBe(1);
    expect(PersonalAccessToken::query()->sole()->id)->not->toBe($firstTokenId);
});
