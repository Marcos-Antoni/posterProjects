## MODIFIED Requirements

### Requirement: Versioned OpenAPI Contract Document

`openapi/v1.json` MUST document exactly the registered `api/*` routes — no more, no fewer. An
automated test MUST assert set equality in both directions between registered routes and documented
operations, failing the build on drift in either direction. Every documented operation that issues a
session to an unauthenticated caller (an unauthenticated token-issuing operation) MUST declare no
`security` key; every other documented operation MUST declare `security: [{"bearerAuth": []}]`. The
exemption is a property of the operation's contract, not an enumerated list of paths. Routes retired by
Marcos OS (projects, issues, board columns, sprints, labels) MUST be unregistered — not kept as stubs —
and MUST be absent from the document; a request to them MUST receive the generic `404` body. The document's
`info.version` MUST be bumped to signal the breaking change to `posterMobile`.

#### Scenario: The contract stays honest

- **GIVEN** the registered `api/*` routes and the operations documented in `openapi/v1.json`
- **WHEN** the contract test runs
- **THEN** both sets SHALL be identical

#### Scenario: Unauthenticated token-issuing operations are exempt from bearerAuth, every other operation still requires it

- **GIVEN** `openapi/v1.json` documents `POST api/v1/login` and `POST api/v1/qr-login` as unauthenticated
  token-issuing operations
- **WHEN** the contract test runs
- **THEN** both operations SHALL declare no `security` key
- **AND** every other documented `api/*` operation SHALL declare `security: [{"bearerAuth": []}]`

#### Scenario: A retired route answers the generic 404

- **GIVEN** a valid `mobile` token
- **WHEN** `GET /api/v1/projects` is requested after the Marcos OS cutover
- **THEN** the response SHALL be `404` with `{"message": "Recurso no encontrado."}`
