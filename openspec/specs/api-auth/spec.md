# API Auth Specification

## Purpose

The `/api/v1` bearer-token surface the `posterMobile` Flutter client authenticates against: a
credentials-to-token exchange, the authenticated identity, revocation, an ability boundary shared
with the existing `/mcp` surface, brute-force protection, a JSON error contract, a browser-reachable
revoke path, and a versioned contract document.

## Requirements

### Requirement: Credentials Exchange For A Mobile Bearer Token

`POST /api/v1/login` MUST accept `email` and `password` and, given valid owner credentials, MUST
respond `200` with a JSON body containing the plain-text Sanctum token: `{"token": "<plain-text>"}`.
The minted token MUST be named `mobile` and MUST carry exactly the ability `['mobile']`. Given
invalid credentials, the endpoint MUST respond `422` reusing the existing Spanish copy: missing/invalid
fields use `LoginRequest::messages()` (`app/Http/Requests/Auth/LoginRequest.php:49-51`); a
recognized-but-wrong email/password pair uses "Estas credenciales no coinciden con nuestros
registros." (`LoginRequest.php:68`).

#### Scenario: Valid credentials mint a mobile token

- GIVEN the owner's correct email and password
- WHEN `POST /api/v1/login` is called
- THEN the response SHALL be `200` with a plain-text token in the body
- AND the stored token SHALL be named `mobile` with ability `['mobile']`

#### Scenario: Wrong password is rejected

- GIVEN a correct email with an incorrect password
- WHEN `POST /api/v1/login` is called
- THEN the response SHALL be `422` with "Estas credenciales no coinciden con nuestros registros."

### Requirement: Authenticated Identity Exposure

`GET /api/v1/user` with a valid `mobile`-ability bearer token MUST respond `200` with the owner
through `UserResource`, wrapped in Laravel's default `{"data": {...}}` envelope. The exposed fields
MUST be exactly `id`, `name`, `email` — no other user attribute (e.g. `email_verified_at`, timestamps,
`password`) MAY be included.

#### Scenario: The owner's identity is returned with a pinned field set

- GIVEN a valid `mobile` bearer token
- WHEN `GET /api/v1/user` is called
- THEN the response SHALL be `200` with `data.id`, `data.name`, `data.email` only

### Requirement: Token Revocation On Logout

`POST /api/v1/logout` MUST revoke only the presenting token via `currentAccessToken()`, leaving any
other token (of either name) untouched, and MUST respond `204` with no body. Any subsequent request
using the revoked token MUST fail with `401`.

#### Scenario: Logout kills only the presenting token

- GIVEN a valid `mobile` bearer token
- WHEN `POST /api/v1/logout` is called
- THEN the response SHALL be `204`
- AND the next request with that token SHALL fail `401`

### Requirement: Single Active Mobile Token

Any successful mobile-session issuance — credentials login via `POST /api/v1/login` OR pass
redemption via `POST /api/v1/qr-login` — MUST revoke every prior token named `mobile` before minting
the new one, and MUST NOT revoke any token named `mcp`. At most one `mobile` token MAY exist per
owner at a time, regardless of which issuance path created it.
(Previously: scoped only to `POST /api/v1/login`; QR redemption now shares the same invariant.)

#### Scenario: A fresh login retires the previous mobile token, not the MCP token

- GIVEN an existing `mobile` token and an existing `mcp` token
- WHEN the owner logs in again via `POST /api/v1/login`
- THEN the previous `mobile` token SHALL stop authenticating
- AND the `mcp` token SHALL remain valid

#### Scenario: A QR redemption retires the previous mobile token, not the MCP token

- GIVEN an existing `mobile` token and an existing `mcp` token, and a valid unconsumed QR pass
- WHEN `POST /api/v1/qr-login` redeems the pass
- THEN the previous `mobile` token SHALL stop authenticating
- AND the `mcp` token SHALL remain valid

### Requirement: Ability Boundary Between `/api/v1` And `/mcp`

`/api/v1/user` and `/api/v1/logout` MUST require the `mobile` ability; `/mcp` MUST require the `mcp`
ability (see the `mcp-server` spec). A token lacking the required ability MUST be refused `403`. A
token carrying the wildcard ability `['*']` — the only kind that existed before this capability shipped
— MUST satisfy both surfaces. This is an accepted, bounded residual: such a token can call both
`/api/v1` and `/mcp` until the owner regenerates it from the MCP settings page, after which the new
token carries only `['mcp']`.

#### Scenario: A mobile token cannot reach `/mcp`

- GIVEN a bearer token with ability `['mobile']` only
- WHEN it is presented to `/mcp`
- THEN the response SHALL be `403`

#### Scenario: An mcp-only token cannot reach `/api/v1`

- GIVEN a bearer token with ability `['mcp']` only
- WHEN it is presented to `GET /api/v1/user`
- THEN the response SHALL be `403`

#### Scenario: A pre-existing wildcard token still reaches both surfaces

- GIVEN a bearer token with ability `['*']`
- WHEN it is presented to `/mcp` and separately to `/api/v1/user`
- THEN both responses SHALL succeed

### Requirement: Login Brute-Force Protection

`POST /api/v1/login` MUST be throttled by a named limiter (`api-login`) enforcing two composite
limits within the same one-minute window: 5 attempts keyed by `email|ip`, AND 10 attempts keyed by
`ip` alone. Both successful and failed attempts MUST count toward each limit. The first attempt that
exceeds either limit MUST respond `429` with a `Retry-After` header (seconds) and the Spanish body
`{"message": "Demasiados intentos de acceso. Por favor, inténtalo de nuevo en {n} segundos."}`.

#### Scenario: The 6th attempt in a minute is throttled

- GIVEN 5 login attempts for the same email from the same IP within one minute
- WHEN a 6th attempt is made
- THEN the response SHALL be `429` with a `Retry-After` header and the Spanish throttle message

### Requirement: JSON Error Response Contract

Every `api/*` error response MUST use one of these shapes:

| Status | Body | Extra |
|---|---|---|
| 401 | `{"message": "No autenticado."}` | `WWW-Authenticate: Bearer` header |
| 403 | `{"message": "Este token no tiene permiso para usar esta API."}` | — |
| 404 | `{"message": "Recurso no encontrado."}` | — |
| 422 | `{"message": "<first error>", "errors": {"field": ["<msg>"]}}` | — |
| 429 | `{"message": "<Spanish throttle text>"}` | `Retry-After` header |

The 404 body MUST NOT leak the Eloquent model's fully-qualified class name, regardless of
`app.debug`. The 403 and 404 renders MUST be typed on `AccessDeniedHttpException` /
`NotFoundHttpException`, NOT on `MissingAbilityException` / Eloquent's `ModelNotFoundException` —
`Handler::prepareException()` rewrites both of the latter into the former before render callbacks
run, so a callback typed on the original exception silently never matches
(`bootstrap/app.php:56-82`).

(History: the 403 row originally promised this Spanish body, but the render was dead code —
`MissingAbilityException` never matched for the reason above, so the app returned the English
framework default. No 404 row originally existed; a route-model-binding failure leaked `No query
results for model [App\Models\Project] XYZ`. Both renders were fixed and committed at `f8aa467`
(`bootstrap/app.php:60-82`), verified by a body-asserting test; `api-projects` documented this
already-fixed contract, it did not implement it. Archive-time correction: this table's 401 row had
drifted — prior spec text read `{"message": "Unauthenticated."}` (the English framework default),
but the shipped code at `bootstrap/app.php:56-58` has always returned the Spanish
`{"message": "No autenticado."}` for `api/*` routes. Corrected here against the verified source
during the `api-labels-comments` archive pass, 2026-08-01.)

#### Scenario: An unauthenticated request gets a bearer challenge

- GIVEN a request to any `api/*` route requiring auth, with no token
- WHEN the request is handled
- THEN the response SHALL be `401` with `WWW-Authenticate: Bearer`

#### Scenario: A token lacking the required ability is refused with the documented body

- GIVEN a valid bearer token that lacks the ability the route requires
- WHEN the request is handled
- THEN the response SHALL be `403` with `{"message": "Este token no tiene permiso para usar esta API."}`

#### Scenario: An unresolvable resource returns a generic 404, never a class name

- GIVEN a request to an `api/*` route whose route-model binding fails to resolve
- WHEN the request is handled
- THEN the response SHALL be `404` with `{"message": "Recurso no encontrado."}`
- AND the body SHALL NOT contain any Eloquent model class name

### Requirement: Web Revocation Of The Mobile Token

An authenticated browser settings screen MUST let the owner revoke the active `mobile` token.
Revoking it MUST NOT affect a token named `mcp`. After revocation, the next `/api/v1/*` request
bearing that token MUST fail `401`. All user-facing copy (labels, confirmation, outcome) MUST be in
Spanish, matching the existing voseo tone of `resources/js/pages/settings/mcp-token.tsx` (e.g.
"Generar", "Regenerar"). The revoke action MUST require an explicit confirmation step, since it is
destructive and tokens never expire.

#### Scenario: The owner revokes the mobile token from the browser

- GIVEN an authenticated owner viewing the token settings screen with an active `mobile` token
- WHEN they confirm the revoke action
- THEN the `mobile` token SHALL stop authenticating immediately
- AND any `mcp` token SHALL remain valid

### Requirement: Versioned OpenAPI Contract Document

`openapi/v1.json` MUST document exactly the registered `api/*` routes — no more, no fewer. An
automated test MUST assert set equality in both directions between registered routes and documented
operations, failing the build on drift in either direction. Every documented operation that issues a
session to an unauthenticated caller (an unauthenticated token-issuing operation) MUST declare no
`security` key; every other documented operation MUST declare `security: [{"bearerAuth": []}]`. The
exemption is a property of the operation's contract, not an enumerated list of paths.
(Previously: the exemption existed only as a hard-coded check for `POST api/v1/login` in
`tests/Feature/ApiContractTest.php:76`; now stated as a spec requirement that generalizes to any
unauthenticated token-issuing operation, so a second such operation extends the rule instead of
breaking it.)

#### Scenario: The contract stays honest

- GIVEN the registered `api/*` routes and the operations documented in `openapi/v1.json`
- WHEN the contract test runs
- THEN both sets SHALL be identical

#### Scenario: Unauthenticated token-issuing operations are exempt from bearerAuth, every other operation still requires it

- GIVEN `openapi/v1.json` documents `POST api/v1/login` and `POST api/v1/qr-login` as unauthenticated
  token-issuing operations
- WHEN the contract test runs
- THEN both operations SHALL declare no `security` key
- AND every other documented `api/*` operation SHALL declare `security: [{"bearerAuth": []}]`
