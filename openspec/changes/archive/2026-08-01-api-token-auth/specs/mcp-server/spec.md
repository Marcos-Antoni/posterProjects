# Delta for MCP Server

Narrows the token-uniqueness invariant to the `mcp` token name (a `mobile` token, added by the
`api-auth` capability, may now coexist) and adds an ability check on `/mcp`. The existing
bearer-challenge and revocation-immediacy requirements (`openspec/specs/mcp-server/spec.md:13-33`) are
unchanged and cross-referenced here, not restated or weakened.

## MODIFIED Requirements

### Requirement: Exactly One MCP Token Exists, And The Plain Text Is Shown Once

The token settings page MUST report that no MCP token exists before one is generated. Generating an
MCP token MUST store exactly one personal access token named `mcp`, MUST NOT disturb any token named
`mobile`, and MUST flash the plain text exactly once. Regenerating MUST kill the previous `mcp` token
immediately, again without touching a `mobile` token. **The plain token MUST NEVER leak into the
persistent page prop.** Guests MUST be redirected to login.

(Previously: scoped to "a token" with no name distinction — `tokens()->delete()` removed every token
regardless of name. Now scoped specifically to tokens named `mcp`, since a `mobile` token minted by
`POST /api/v1/login` must survive MCP token generation and regeneration.)

Note: `tests/Feature/McpTokenTest.php:26` ("generating a token stores a single pat and flashes the
plain text once") asserts `$user->tokens()->count() === 1` — an assumption this delta breaks by
design once a `mobile` token can coexist. The rewritten test MUST assert the count of tokens named
`mcp` is exactly 1, MUST assert the stored token's name is `mcp`, and MUST assert a pre-existing
`mobile` token still exists afterward and is unmodified — not loosen or drop the uniqueness assertion.

#### Scenario: The plain token never persists in page state

- GIVEN an MCP token has just been generated
- WHEN the page renders
- THEN the plain text SHALL appear only in the one-time flash
- AND the persistent token prop SHALL NOT contain it

#### Scenario: Regenerating invalidates the old MCP token at once

- GIVEN an existing MCP token
- WHEN the user regenerates it
- THEN the previous MCP token SHALL stop working immediately
- AND exactly one token named `mcp` SHALL remain stored

#### Scenario: A mobile token survives MCP token generation

- GIVEN an existing `mobile` token
- WHEN the owner generates or regenerates the MCP token
- THEN the `mobile` token SHALL remain valid and untouched
- AND exactly one token named `mcp` SHALL exist

## ADDED Requirements

### Requirement: MCP Access Requires The `mcp` Ability

In addition to the existing bearer-authentication requirement ("Access Requires A Valid Bearer
Token", `spec.md:13-33`, unchanged), `/mcp` MUST require the presenting token to carry the `mcp`
ability. A token lacking it MUST be refused `403`. A token carrying the wildcard ability `['*']` —
the only kind that existed before this change — MUST continue to authenticate, preserving the
owner's live Claude Desktop integration without regression.

#### Scenario: A mobile-only token is refused at `/mcp`

- GIVEN a bearer token with ability `['mobile']` only
- WHEN it is presented to `/mcp`
- THEN the response SHALL be `403`

#### Scenario: A pre-existing wildcard token keeps working at `/mcp`

- GIVEN a bearer token with ability `['*']`, minted before this change
- WHEN it is presented to `/mcp`
- THEN the request SHALL authenticate as before
