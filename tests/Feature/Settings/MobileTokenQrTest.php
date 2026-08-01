<?php

use App\Models\QrLoginPass;
use App\Models\User;

test('a guest cannot mint a pass and no row is written', function () {
    $response = $this->post('/settings/mobile-token/qr');

    $response->assertRedirect('/login');
    $this->assertDatabaseCount('qr_login_passes', 0);
});

test('an authenticated owner mints a pass and receives the plaintext once, while only its hash is stored', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/settings/mobile-token/qr');

    $response->assertOk();
    $response->assertJsonPath('state', 'live');
    $payload = $response->json('payload');
    expect($payload)->toBeString()->toMatch('/^pposter_qr_v1:[A-Za-z0-9_-]{43}$/');
    expect($response->json('expires_at'))->not->toBeNull();

    $stored = QrLoginPass::query()->sole();
    expect($stored->token_hash)->toBe(hash('sha256', $payload));
});

test('minting a second pass invalidates the first unconsumed pass', function () {
    $user = User::factory()->create();

    $first = $this->actingAs($user)->postJson('/settings/mobile-token/qr');
    $firstPayload = $first->json('payload');

    $second = $this->actingAs($user)->postJson('/settings/mobile-token/qr');
    $second->assertOk();
    $secondPayload = $second->json('payload');

    expect($secondPayload)->not->toBe($firstPayload);
    $this->assertDatabaseCount('qr_login_passes', 1);

    $stored = QrLoginPass::query()->sole();
    expect($stored->token_hash)->toBe(hash('sha256', $secondPayload));
});

test('minting over an already-consumed pass writes nothing and reports the consumed state', function () {
    $user = User::factory()->create();
    $pass = QrLoginPass::factory()->for($user)->consumed()->create();
    $consumedAt = $pass->consumed_at;
    $consumedIp = $pass->consumed_ip;

    $response = $this->actingAs($user)->postJson('/settings/mobile-token/qr');

    $response->assertOk();
    $response->assertJsonPath('state', 'consumed');
    $response->assertJsonPath('consumed_ip', $consumedIp);
    $response->assertJsonMissingPath('payload');

    $this->assertDatabaseCount('qr_login_passes', 1);
    expect($pass->fresh()->consumed_at->equalTo($consumedAt))->toBeTrue();
});

test('an acknowledged mint over a consumed pass issues a fresh live pass and keeps the consumed row', function () {
    $user = User::factory()->create();
    $pass = QrLoginPass::factory()->for($user)->consumed()->create();
    $consumedAt = $pass->consumed_at;
    $consumedIp = $pass->consumed_ip;

    $response = $this->actingAs($user)->postJson('/settings/mobile-token/qr', [
        'acknowledge_consumed' => true,
    ]);

    $response->assertOk();
    $response->assertJsonPath('state', 'live');
    $payload = $response->json('payload');
    expect($payload)->toBeString()->toMatch('/^pposter_qr_v1:[A-Za-z0-9_-]{43}$/');
    expect($response->json('expires_at'))->not->toBeNull();

    $this->assertDatabaseCount('qr_login_passes', 2);

    $stillConsumed = $pass->fresh();
    expect($stillConsumed->consumed_at->equalTo($consumedAt))->toBeTrue();
    expect($stillConsumed->consumed_ip)->toBe($consumedIp);

    $fresh = QrLoginPass::query()->whereNull('consumed_at')->sole();
    expect($fresh->token_hash)->toBe(hash('sha256', $payload));
});

test('status never returns the payload, across live, consumed, expired, and none states', function () {
    $user = User::factory()->create();

    $none = $this->actingAs($user)->getJson('/settings/mobile-token/qr/status');
    $none->assertOk();
    $none->assertJsonPath('state', 'none');
    $none->assertJsonMissingPath('payload');

    $mint = $this->actingAs($user)->postJson('/settings/mobile-token/qr');
    $mint->assertOk();

    $live = $this->actingAs($user)->getJson('/settings/mobile-token/qr/status');
    $live->assertOk();
    $live->assertJsonPath('state', 'live');
    $live->assertJsonMissingPath('payload');

    QrLoginPass::query()->sole()->update(['consumed_at' => now(), 'consumed_ip' => '203.0.113.9']);

    $consumed = $this->actingAs($user)->getJson('/settings/mobile-token/qr/status');
    $consumed->assertOk();
    $consumed->assertJsonPath('state', 'consumed');
    $consumed->assertJsonMissingPath('payload');

    QrLoginPass::query()->sole()->update(['consumed_at' => null, 'expires_at' => now()->subSecond()]);

    $expired = $this->actingAs($user)->getJson('/settings/mobile-token/qr/status');
    $expired->assertOk();
    $expired->assertJsonPath('state', 'expired');
    $expired->assertJsonMissingPath('payload');
});
