<?php

use App\Models\QrLoginPass;
use App\Models\User;

/**
 * Full QR login card lifecycle through the UI: gated behind an explicit
 * click (so a drive-by visit never mints a credential — design.md), then a
 * simulated remote redemption flips the card to the consumed state within
 * one poll cycle, with no live credential ever touching the DOM here.
 */
test('a user reveals the QR code, sees the countdown, and the card flips to consumed once the pass is redeemed', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/settings/mobile-token');

    $page->assertSee('Mostrar código QR')
        ->assertNotPresent('[data-testid="qr-code"]')
        ->assertNoJavascriptErrors();

    $page->click('Mostrar código QR')
        ->assertPresent('[data-testid="qr-code"]')
        ->assertSee('El código expira en')
        ->assertNoJavascriptErrors();

    // Simulate the phone's redemption directly, exactly as
    // `QrLoginController::redeem` would leave the row — no live credential
    // ever touches the DOM in this test.
    QrLoginPass::query()->sole()->update([
        'consumed_at' => now(),
        'consumed_ip' => '203.0.113.9',
    ]);

    // The card polls every 3s; wait past one full cycle.
    $page->wait(4)
        ->assertNotPresent('[data-testid="qr-code"]')
        ->assertSee('Se inició sesión en un teléfono')
        ->assertNoJavascriptErrors();
});
