# Delta for Api-Auth

## MODIFIED Requirements

### Requirement: JSON Error Response Contract

Every `api/*` error response MUST use one of these shapes:

| Status | Body | Extra |
|---|---|---|
| 401 | `{"message": "Unauthenticated."}` | `WWW-Authenticate: Bearer` header |
| 403 | `{"message": "Este token no tiene permiso para usar esta API."}` | — |
| 404 | `{"message": "Recurso no encontrado."}` | — |
| 422 | `{"message": "<first error>", "errors": {"field": ["<msg>"]}}` | — |
| 429 | `{"message": "<Spanish throttle text>"}` | `Retry-After` header |

The 404 body MUST NOT leak the Eloquent model's fully-qualified class name, regardless of
`app.debug`.

(Previously: the 403 row promised this Spanish body, but the render was dead code —
`MissingAbilityException` never matched because `Handler::prepareException()` rewrites any
status-less `AuthorizationException` into `AccessDeniedHttpException` before callbacks run, so the
app returned the English framework default. No 404 row existed; a route-model-binding failure leaked
`No query results for model [App\Models\Project] XYZ`. Both renders are already fixed and committed
at `f8aa467` — `bootstrap/app.php:60-82` — verified by a body-asserting test. `api-projects` only
documents this already-fixed contract; it does not implement it.)

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
