<?php

use App\Models\User;

/**
 * @return array{jsonrpc: string, id: int, method: string, params: array<string, mixed>}
 */
function mcpInitializePayload(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0'],
        ],
    ];
}

function mcpHeaders(string $token): array
{
    return [
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
    ];
}

test('an authenticated client can initialize against the mcp server', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mcp')->plainTextToken;

    $response = $this->postJson('/mcp', mcpInitializePayload(), mcpHeaders($token));

    $response->assertOk();
    $response->assertJsonPath('result.serverInfo.name', 'Poster Projects');
});

test('an authenticated client can list the server tools', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mcp')->plainTextToken;

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => (object) [],
    ], mcpHeaders($token));

    $response->assertOk();

    expect($response->json('result.tools'))->toBeArray();
});

test('a request without a token is rejected with a bearer challenge', function () {
    $response = $this->postJson('/mcp', mcpInitializePayload(), [
        'Accept' => 'application/json, text/event-stream',
    ]);

    $response->assertStatus(401);

    expect($response->headers->get('WWW-Authenticate'))->toStartWith('Bearer');
});

test('a revoked token stops authenticating immediately', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mcp')->plainTextToken;

    // Regenerating from the settings page deletes every previous token.
    $user->tokens()->delete();
    $user->createToken('mcp');

    $response = $this->postJson('/mcp', mcpInitializePayload(), mcpHeaders($token));

    $response->assertStatus(401);

    expect($response->headers->get('WWW-Authenticate'))->toStartWith('Bearer');
});
