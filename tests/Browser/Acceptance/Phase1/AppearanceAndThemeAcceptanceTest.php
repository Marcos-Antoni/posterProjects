<?php

/*
| Phase 1 acceptance (independent tester) — browser checks of the auth
| appearance requirement and the D16 visual foundation (tokens, fonts).
*/

use App\Models\User;

const P1A_LIGHT_BG = 'rgb(238, 242, 239)'; // #EEF2EF Niebla
const P1A_DARK_BG = 'rgb(20, 32, 34)';     // #142022 Basalto

test('"sistema" follows a dark OS on the first render', function () {
    $user = User::factory()->create(); // default: system

    $this->actingAs($user);

    visit('/settings/appearance')->inDarkMode()
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertScript('getComputedStyle(document.body).backgroundColor', P1A_DARK_BG)
        ->assertChecked('input[value="system"]')
        ->assertNoJavascriptErrors();
});

test('"sistema" follows a light OS on the first render', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    visit('/settings/appearance')->inLightMode()
        ->assertScript('document.documentElement.classList.contains("dark")', false)
        ->assertScript('getComputedStyle(document.body).backgroundColor', P1A_LIGHT_BG);
});

test('a stored "claro" stays light even when the OS is dark', function () {
    $user = User::factory()->create(['appearance' => 'light']);

    $this->actingAs($user);

    visit('/settings/mcp-token')->inDarkMode()
        ->assertScript('document.documentElement.classList.contains("dark")', false)
        ->assertScript('getComputedStyle(document.body).backgroundColor', P1A_LIGHT_BG);
});

test('a stored "oscuro" stays dark even when the OS is light', function () {
    $user = User::factory()->create(['appearance' => 'dark']);

    $this->actingAs($user);

    visit('/settings/mobile-token')->inLightMode()
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertScript('getComputedStyle(document.body).backgroundColor', P1A_DARK_BG);
});

test('the interface font is Overpass and it actually loads', function () {
    $page = visit('/login');

    $page->assertScript('getComputedStyle(document.body).fontFamily.includes("Overpass")', true)
        ->assertScript('document.fonts.ready.then(() => document.fonts.check("16px Overpass"))', true)
        ->assertNoJavascriptErrors();
});

test('the login screen carries the mockup-01 copy and a single primary action', function () {
    visit('/login')
        ->assertSee('Marcos OS')
        ->assertSee('Al entrar ves una sola tarea y su versión de 2 minutos.')
        ->assertSee('Cuenta única de Marco. No hay registro.')
        ->assertSee('Recordarme en este equipo')
        ->assertCount('button[type="submit"]', 1)
        ->assertNoJavascriptErrors();
});

test('the appearance page has exactly one control (three radios) and no profile/password form', function () {
    $this->actingAs(User::factory()->create());

    visit('/settings/appearance')
        ->assertCount('input[type="radio"][name="appearance"]', 3)
        ->assertCount('input[type="password"]', 0)
        ->assertCount('input[name="email"]', 0)
        ->assertCount('input[name="name"]', 0)
        ->assertSee('php artisan marcos:set-password')
        ->assertNoJavascriptErrors();
});

test('the password confirmation screen shows the 15-minute note and a way out', function () {
    $this->actingAs(User::factory()->create());

    visit('/confirm-password')
        ->assertSee('Confirmá tu contraseña')
        ->assertSee('Confirmar y seguir')
        ->assertSee('Volver sin hacerlo')
        ->assertSee('Vale 15 minutos')
        ->assertNoJavascriptErrors();
});

test('a wrong password on the login screen shows the mockup-01 error copy, keeps the email and focuses the password', function () {
    $user = User::factory()->create(['email' => 'p1a-err@example.test']);

    visit('/login')
        ->fill('email', 'p1a-err@example.test')
        ->fill('password', 'wrong-one')
        ->press('button[type="submit"]')
        ->assertSee('El correo o la contraseña no coinciden.')
        ->assertSee('Escribí la contraseña de nuevo.')
        ->assertValue('input[name="email"]', 'p1a-err@example.test')
        ->assertValue('input[name="password"]', '')
        ->assertScript('document.activeElement.name', 'password')
        ->assertNoJavascriptErrors();
});
