# Delta for API Auth

## MODIFIED Requirements

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
