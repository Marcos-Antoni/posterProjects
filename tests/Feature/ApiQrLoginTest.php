<?php

use App\Enums\TokenName;
use App\Models\QrLoginPass;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, string>
 */
function apiQrBearerHeaders(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

/**
 * A syntactically valid QR pass plaintext matching the production grammar
 * (design.md "Payload grammar") — 14-char prefix + 43 base64url chars.
 */
function qrLoginPlaintext(): string
{
    return 'pposter_qr_v1:'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

test('a valid pass redemption issues a mobile token indistinguishable from a credentials login', function () {
    $user = User::factory()->create();
    $plain = qrLoginPlaintext();
    QrLoginPass::factory()->for($user)->create(['token_hash' => hash('sha256', $plain)]);

    $response = $this->postJson('/api/v1/qr-login', ['token' => $plain]);

    $response->assertOk();
    $token = $response->json('token');
    expect($token)->toBeString()->not->toBe('');

    $stored = $user->tokens()->where('name', TokenName::Mobile->value)->sole();
    expect($stored->abilities)->toBe([TokenName::Mobile->value]);

    $me = $this->getJson('/api/v1/user', apiQrBearerHeaders($token));
    $me->assertOk();
    expect($me->json('data'))->toBe([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ]);
});

test('consumption is a single UPDATE with no preceding SELECT of the row', function () {
    $user = User::factory()->create();
    $plain = qrLoginPlaintext();
    QrLoginPass::factory()->for($user)->create(['token_hash' => hash('sha256', $plain)]);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, 'qr_login_passes')) {
            $statements[] = $query->sql;
        }
    });

    $this->postJson('/api/v1/qr-login', ['token' => $plain])->assertOk();

    expect($statements)->not->toBeEmpty();
    expect($statements[0])->toStartWith('update');
    expect(collect($statements)->filter(fn (string $sql): bool => str_starts_with($sql, 'update')))->toHaveCount(1);
});

test('the first of two redemptions succeeds, the second does not', function () {
    $user = User::factory()->create();
    $plain = qrLoginPlaintext();
    QrLoginPass::factory()->for($user)->create(['token_hash' => hash('sha256', $plain)]);

    $first = $this->postJson('/api/v1/qr-login', ['token' => $plain]);
    $first->assertOk();

    $second = $this->postJson('/api/v1/qr-login', ['token' => $plain]);
    $second->assertStatus(422);

    expect($user->tokens()->where('name', TokenName::Mobile->value)->count())->toBe(1);
});

test('redeeming an already-consumed pass fails and its audit row is left untouched', function () {
    $user = User::factory()->create();
    $plain = qrLoginPlaintext();
    $pass = QrLoginPass::factory()->for($user)->consumed()->create(['token_hash' => hash('sha256', $plain)]);
    $consumedAt = $pass->consumed_at;

    $response = $this->postJson('/api/v1/qr-login', ['token' => $plain]);

    $response->assertStatus(422);
    expect($user->tokens()->where('name', TokenName::Mobile->value)->count())->toBe(0);
    expect($pass->fresh()->consumed_at->equalTo($consumedAt))->toBeTrue();
});

test('a pass expires exactly 60 seconds after creation, and a 61-second-old pass is refused', function () {
    $user = User::factory()->create();
    $plain = qrLoginPlaintext();
    $pass = QrLoginPass::factory()->for($user)->create(['token_hash' => hash('sha256', $plain)]);

    expect($pass->created_at->diffInSeconds($pass->expires_at))->toEqual(60);

    $this->travel(61)->seconds();

    $response = $this->postJson('/api/v1/qr-login', ['token' => $plain]);
    $response->assertStatus(422);
});

test('unknown, expired, consumed, and malformed passes all return the identical uniform failure', function (Closure $setup) {
    $token = $setup();

    $response = $this->postJson('/api/v1/qr-login', ['token' => $token]);

    $response->assertStatus(422);
    $response->assertExactJson([
        'message' => 'El código QR no es válido o expiró.',
        'errors' => ['token' => ['El código QR no es válido o expiró.']],
    ]);
})->with([
    'unknown' => [fn (): string => qrLoginPlaintext()],
    'expired' => [function (): string {
        $plain = qrLoginPlaintext();
        QrLoginPass::factory()->for(User::factory())->expired()->create(['token_hash' => hash('sha256', $plain)]);

        return $plain;
    }],
    'consumed' => [function (): string {
        $plain = qrLoginPlaintext();
        QrLoginPass::factory()->for(User::factory())->consumed()->create(['token_hash' => hash('sha256', $plain)]);

        return $plain;
    }],
    'malformed' => [fn (): string => 'not-a-valid-qr-token'],
]);

test('a QR redemption retires the previous mobile token, not the mcp token', function () {
    $user = User::factory()->create();
    $user->createToken(TokenName::Mcp->value, [TokenName::Mcp->value]);
    $previousMobile = $user->createToken(TokenName::Mobile->value, [TokenName::Mobile->value])->plainTextToken;

    $plain = qrLoginPlaintext();
    QrLoginPass::factory()->for($user)->create(['token_hash' => hash('sha256', $plain)]);

    $response = $this->postJson('/api/v1/qr-login', ['token' => $plain]);
    $response->assertOk();

    expect($user->tokens()->where('name', TokenName::Mobile->value)->count())->toBe(1)
        ->and($user->tokens()->where('name', TokenName::Mcp->value)->count())->toBe(1);

    // See ApiAuthTest's identical reset for why this is required here.
    $this->app['auth']->forgetGuards();

    $dead = $this->getJson('/api/v1/user', apiQrBearerHeaders($previousMobile));
    $dead->assertStatus(401);
});

test('the 11th redemption attempt from one IP in a minute is throttled', function () {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson('/api/v1/qr-login', ['token' => 'not-a-valid-qr-token']);
    }

    $response = $this->postJson('/api/v1/qr-login', ['token' => 'not-a-valid-qr-token']);

    $response->assertStatus(429);
    expect($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->json('message'))->toStartWith('Demasiados intentos de acceso.');
});
