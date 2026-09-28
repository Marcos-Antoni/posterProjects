<?php

use App\Enums\Appearance;
use App\Models\User;

/**
 * Settings → Apariencia: one control, saved on choice, applied without a
 * reload, and stored on the user so a brand-new browser session renders
 * the same theme from the first paint.
 */
test('the owner picks oscuro and the page turns dark at once and is saved', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/settings/appearance');

    $page->assertSee('Se guarda al elegir.')
        ->assertCount('input[type="radio"][name="appearance"]', 3)
        ->assertChecked('input[value="system"]')
        ->click('Oscuro')
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertScript('document.documentElement.dataset.appearance', 'dark')
        ->assertNoJavascriptErrors();

    expect($user->fresh()->appearance)->toBe(Appearance::Dark);
});

test('a stored oscuro renders dark in a fresh browser right after logging in, with the selector on oscuro', function () {
    User::factory()->create(['email' => 'pilot@example.com', 'appearance' => Appearance::Dark]);

    $page = visit('/login');

    $page->assertScript('document.documentElement.classList.contains("dark")', false)
        ->fill('email', 'pilot@example.com')
        ->fill('password', 'password')
        ->press('button[type="submit"]')
        ->assertPathIs('/objectives')
        ->assertScript('document.documentElement.classList.contains("dark")', true);

    $page->navigate('/settings/appearance')
        ->assertChecked('input[value="dark"]')
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertNoJavascriptErrors();
});

test('choosing claro removes the dark class', function () {
    $user = User::factory()->create(['appearance' => Appearance::Dark]);

    $this->actingAs($user);

    $page = visit('/settings/appearance');

    $page->assertScript('document.documentElement.classList.contains("dark")', true)
        ->click('Claro')
        ->assertScript('document.documentElement.classList.contains("dark")', false)
        ->assertScript('document.documentElement.dataset.appearance', 'light')
        ->assertNoJavascriptErrors();

    expect($user->fresh()->appearance)->toBe(Appearance::Light);
});
