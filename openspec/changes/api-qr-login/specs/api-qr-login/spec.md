# API QR Login Specification

## Purpose

A second path to a `mobile` bearer token: an already-authenticated browser mints a short-lived,
single-use pass; an unauthenticated phone redeems it for a token indistinguishable from a
credentials login. Covers pass minting, entropy/TTL/single-liveness, atomic single-use redemption,
the non-oracle failure contract, the redemption limiter, and the in-page consumption signal.

## Requirements

### Requirement: Pass Minting Requires An Authenticated Web Session

`POST settings/mobile-token/qr` MUST require an authenticated web session; a guest request MUST NOT
mint a pass and MUST NOT write a `qr_login_passes` row. On success the response MUST be `200` JSON
containing the plaintext pass payload (`pposter_qr_v1:<43-char base64url>`) and its `expires_at`. The
plaintext MUST be returned exactly once, in this response only — the database MUST store only
`hash('sha256', <payload>)`, and no other endpoint (including the status poll) MAY ever return it again.

#### Scenario: An authenticated owner mints a pass and receives the plaintext once

- GIVEN an authenticated owner viewing `settings/mobile-token`
- WHEN `POST settings/mobile-token/qr` is called
- THEN the response SHALL be `200` with the plaintext pass payload and its expiry
- AND the stored `qr_login_passes` row SHALL contain only the SHA-256 hash of that payload

#### Scenario: A guest cannot mint a pass

- GIVEN no authenticated web session
- WHEN `POST settings/mobile-token/qr` is called
- THEN the response SHALL redirect to login
- AND no `qr_login_passes` row SHALL be written

### Requirement: Pass Entropy, TTL, And One Live Pass Per Owner

A minted pass MUST use a cryptographically random 256-bit value (`random_bytes(32)`, base64url) as
its plaintext, and MUST expire exactly 60 seconds after creation. Minting a new pass for an owner
MUST invalidate any previous unconsumed pass belonging to that owner, so at most one pass is
redeemable per owner at any time.

#### Scenario: Minting a second pass invalidates the first

- GIVEN an owner with one live, unconsumed pass
- WHEN the owner mints a new pass
- THEN the previous pass SHALL no longer be redeemable
- AND only the new pass SHALL be live

#### Scenario: A pass older than its TTL is refused

- GIVEN a pass minted 61 seconds ago that was never consumed
- WHEN `POST /api/v1/qr-login` redeems it
- THEN the response SHALL be the uniform redemption failure (Requirement: Uniform Redemption Failure)

### Requirement: Redemption Yields A Token Indistinguishable From Credentials Login

Given a valid, unconsumed, unexpired pass, `POST /api/v1/qr-login` MUST respond `200` with the
byte-identical shape of `POST /api/v1/login`: `{"token": "<plain-text>"}`. The minted token MUST be
named `mobile` and MUST carry exactly the ability `['mobile']`. No response field, header, or
subsequent API behavior MAY reveal that the session originated from a QR pass rather than credentials.

#### Scenario: A valid pass redemption is indistinguishable from a credentials login

- GIVEN a valid, unconsumed, unexpired pass
- WHEN `POST /api/v1/qr-login` redeems it
- THEN the response SHALL be `200` with a plain-text token in the same shape as `POST /api/v1/login`
- AND the stored token SHALL be named `mobile` with ability `['mobile']`
- AND `GET /api/v1/user` with that token SHALL succeed exactly as with a credentials-obtained token

### Requirement: Atomic Single-Use Consumption

Consuming a pass MUST be one conditional `UPDATE` statement (`SET consumed_at = ?, consumed_ip = ?
WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > ?`) with no preceding `SELECT` of the
row. The controller MUST issue a token only when the update reports exactly one affected row
(`affected === 1`); any other result MUST short-circuit to the uniform redemption failure without
issuing a token. Two redemption attempts of the same pass MUST yield exactly one successful session.

#### Scenario: The first of two redemptions succeeds, the second does not

- GIVEN a valid, unconsumed, unexpired pass
- WHEN `POST /api/v1/qr-login` redeems it twice in sequence
- THEN the first response SHALL be `200` with a new `mobile` token
- AND the second response SHALL be the uniform redemption failure
- AND exactly one `mobile` token SHALL exist for that owner afterward

#### Scenario: Consumption is a single UPDATE with no preceding SELECT

- GIVEN a valid pass
- WHEN `POST /api/v1/qr-login` redeems it
- THEN exactly one `UPDATE` statement SHALL be issued against `qr_login_passes`
- AND no `SELECT` of that row SHALL precede it

### Requirement: Uniform Redemption Failure (Non-Oracle Response)

Unknown, expired, already-consumed, and malformed pass values presented to `POST /api/v1/qr-login`
MUST all produce the exact same response: `422 {"message": "El código QR no es válido o expiró.",
"errors": {"token": [...]}}`. No status code, message, or body field MAY differ across the four cases.

#### Scenario: An unknown pass value returns the uniform failure

- GIVEN a pass value that was never minted
- WHEN `POST /api/v1/qr-login` redeems it
- THEN the response SHALL be `422` with the uniform failure body

#### Scenario: An expired pass returns the uniform failure

- GIVEN a pass minted more than 60 seconds ago
- WHEN `POST /api/v1/qr-login` redeems it
- THEN the response SHALL be `422` with the uniform failure body, identical to the unknown-pass case

#### Scenario: An already-consumed pass returns the uniform failure

- GIVEN a pass that was already redeemed once
- WHEN `POST /api/v1/qr-login` redeems it again
- THEN the response SHALL be `422` with the uniform failure body, identical to the unknown-pass case

#### Scenario: A malformed pass value returns the uniform failure

- GIVEN a value that does not match the pass payload grammar (`pposter_qr_v1:[A-Za-z0-9_-]{43}`)
- WHEN `POST /api/v1/qr-login` redeems it
- THEN the response SHALL be `422` with the uniform failure body, identical to the unknown-pass case

### Requirement: Redemption Rate Limiting

`POST /api/v1/qr-login` MUST be throttled by a named limiter (`api-qr-redeem`) allowing 10 requests
per minute keyed by IP alone, since no identity exists before a pass is validated. The first request
exceeding the limit MUST respond `429` with a `Retry-After` header and the Spanish throttle body
shared with `api-login`.

#### Scenario: The 11th redemption attempt from one IP in a minute is throttled

- GIVEN 10 `POST /api/v1/qr-login` requests from the same IP within one minute
- WHEN an 11th request is made from that IP
- THEN the response SHALL be `429` with a `Retry-After` header and the Spanish throttle message

### Requirement: Web Feedback On Consumption

The `settings/mobile-token` page MUST let the owner observe, without leaving the page, that a live
pass they minted was consumed, including the time and the redeeming IP, positioned so the existing
revoke action is immediately reachable. Reaching a terminal state (consumed or expired) MUST stop
further polling.

#### Scenario: The owner sees that their pass was redeemed

- GIVEN the owner has a live pass displayed and someone redeems it
- WHEN the owner's browser next polls the pass status
- THEN the page SHALL show that a session was started, with the time and the redeeming IP
- AND polling SHALL stop

### Requirement: Owner Acknowledgement Mints Past A Consumed Pass

`POST settings/mobile-token/qr` MUST NOT mint a fresh pass over a consumed latest pass unless the
request explicitly carries `acknowledge_consumed`. Without that flag, the endpoint MUST behave
exactly as in the previous requirement: write nothing and report `state: consumed`, so an automatic
or incidental re-mint (a status poll, a prefetch, a page refresh) can never silently discard the
consumption notice. WHEN the flag is present, the endpoint MUST mint a fresh pass exactly as it
would over an unconsumed latest pass, and the previously consumed row MUST remain in the database,
unmodified, as an audit record — only a subsequent unconsumed pass created by that owner is deleted
before the fresh one is inserted.

The `settings/mobile-token` page MUST offer the owner an explicit action, reachable only from the
consumed state's UI, that sends this flag. No other code path (the status poll, the countdown timer,
a page load, or a re-mint triggered by nearing expiry) MAY send it.

#### Scenario: A plain mint over a consumed pass writes nothing

- GIVEN an owner whose latest pass is already consumed
- WHEN `POST settings/mobile-token/qr` is called without `acknowledge_consumed`
- THEN the response SHALL be `200` reporting `state: consumed` with no `payload`
- AND no `qr_login_passes` row SHALL be written or modified

#### Scenario: An acknowledged mint over a consumed pass mints a fresh pass and keeps the audit trail

- GIVEN an owner whose latest pass is already consumed
- WHEN `POST settings/mobile-token/qr` is called with `acknowledge_consumed: true`
- THEN the response SHALL be `200` with a fresh plaintext pass payload and its expiry, exactly as an
  ordinary mint
- AND the previously consumed row SHALL remain in the database with its original `consumed_at` and
  `consumed_ip` unchanged
- AND the new pass SHALL be the only unconsumed `qr_login_passes` row for that owner

#### Scenario: The owner regenerates a pass from the consumed state in the browser

- GIVEN the owner's card is showing the consumed notice
- WHEN the owner clicks the explicit "Regenerar código QR" action
- THEN the card SHALL mint and display a fresh pass
- AND the consumed row from the previous pass SHALL remain unchanged in the database
