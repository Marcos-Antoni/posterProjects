# Proposal: Projects Read API (`api-projects`)

## Intent

`api-token-auth` gave `posterMobile` a bearer token but nothing to spend it on: `/api/v1` exposes only
`login`, `user`, `logout` (`routes/api.php:6-13`). The Flutter client's first useful screen is a project
list, so this change ships the read surface it needs and pins the JSON shape the app will depend on for
its whole life. It is change 2 of 9; every later resource change nests under `projects/{project}`, so the
identifier and resource conventions decided here are inherited eight more times.

## Scope decision: **read-only**

Writes are deferred to a follow-up `api-projects-write`. Reasons, in order of weight:

1. **Budget.** Reads alone forecast ~450 lines. Adding `store`, `update`, archive, restore, and
   force-delete adds five operations, ~450 more lines of hand-authored OpenAPI, and ~250 lines of tests
   → ~1270 total. That is 3x the review budget for one PR.
2. **Risk asymmetry.** `forceDelete` cascades irreversibly across issues, sprints, columns, labels, and
   comments (`openspec/specs/projects/spec.md:86-99`). Putting an irreversible cascade behind a
   never-expiring phone token deserves its own risk review, not a footnote in a list-screen PR.
3. **Product.** Creating and renaming projects is a desk activity. Reading them is the phone activity.

Reads are non-negotiable and complete on their own: the client can render a list and a detail screen.

### In Scope

- `GET /api/v1/projects` — the owner's active projects with issue counts.
- `GET /api/v1/projects/{project}` — one project by its `key`.
- `App\Http\Resources\ProjectResource` — the pinned mobile shape.
- Fix the `api/*` 403 render, which is dead code today (Decision 7), and add a 404 render.
- `openapi/v1.json` delta (Decision 6).

### Out of Scope

- Create / update / archive / restore / force-delete (`api-projects-write`).
- `GET /api/v1/projects/trash` — deferred with the writes, because a trash list you cannot restore
  from or empty is read-only dead weight.
- Issues, board, sprints, labels, comments (changes 3-9). Anything Flutter.

## Capabilities

### New Capabilities

- `api-projects`: the `/api/v1/projects` read surface — its owner scoping, its pinned resource shape,
  its trashed-project semantics, and its aggregate-loading guarantee.

### Modified Capabilities

- `api-auth`: two error-contract requirements change. The documented 403 body
  (`openapi/v1.json:127,165`) is **not** what the app returns (Decision 7), and a 404 body is now
  reachable and currently leaks the model FQCN (Decision 8). Both need spec rows and RED tests.
- `projects`: **unchanged.** No web behavior is touched; this change only mirrors it over HTTP.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | `ProjectResource` exposes exactly `id`, `key`, `name`, `description`, `issues_count`. **Excluded:** `owner_id`, `next_issue_number`, `deleted_at`, timestamps, `url`. | Mirrors the shape the MCP tool already publishes for the same entity (`app/Mcp/Tools/Projects/ListProjects.php:32-39`), so the owner sees one project representation across both machine surfaces. `next_issue_number` is the internal allocator (`app/Models/Project.php:87-100`) — exposing it lets a client predict unissued issue keys, and it is the sort of column that is impossible to retract once a released app reads it. `owner_id` is a constant in a single-owner app. Timestamps and `url` are omitted deliberately: **adding a field later is backward compatible, removing one is not**. `id` stays because the Flutter client needs a stable local primary key, but it is never addressable (Decision 2). |
| 2 | URLs use `key`: `Route::get('projects/{project:key}', ...)`. | Matches every nested web route (`routes/web.php:65,70,72,86`) and both write-side MCP tools, which take `project_key` (`app/Mcp/Tools/Projects/UpdateProject.php:66`). Changes 3-9 nest under this segment, so a mismatch here costs eight times. Sequential ids also leak project count; keys do not. |
| 3 | Neither endpoint returns an archived project. The list is `$request->user()->projects()` (active only); `show` does **not** use `withTrashed()`, so an archived key returns 404. No `archived` field is exposed. | Mirrors `ProjectController::index()` (`ProjectController.php:24-28`) and the spec requirement that archived projects leave the active list (`openspec/specs/projects/spec.md:39-51`). 404 is the honest signal to a client holding a stale key: *this is gone, drop it from your cache*. An `archived` boolean would be dead weight on a surface that can never return `true`; it becomes an additive field when the trash endpoint ships. |
| 4 | `issues_count` comes from `withCount('issues')` on **both** queries. `ProjectResource` reads the `issues_count` attribute and MUST NOT call `$this->issues()->count()`. Guarded by a query-count test, not by convention. | `Model::preventLazyLoading` is **not** enabled — `AppServiceProvider::configureDefaults()` sets only `Date::use`, `DB::prohibitDestructiveCommands`, and `Password::defaults` (`app/Providers/AppServiceProvider.php:38-55`). Nothing would catch a lazy count at runtime; on a phone over a slow connection an N+1 is the difference between one round trip and twenty. The test seeds 1 project, then 5, and asserts the query count is identical. |
| 5 | **No pagination.** `ProjectResource::collection(...)` → `{"data": [...]}`. | Single-owner app; the web index, the sidebar, and the MCP tool all return the full list unpaginated (`ProjectController.php:24-28`). The realistic ceiling is tens of rows of five scalar fields. The reversal is cheap and non-breaking: a paginated `AnonymousResourceCollection` keeps `data` as a top-level array and only **adds** `links`/`meta`, so a client reading `data` survives the switch. The OpenAPI description will say so explicitly, so the mobile side never hard-codes "the response has exactly one key". |
| 6 | Two operations added to `openapi/v1.json`: `GET /api/v1/projects` (`listProjects`) and `GET /api/v1/projects/{project}` (`getProject`), tag `Projects`, both `security: [{bearerAuth: []}]`; schemas `Project`, `ProjectResponse`, `ProjectCollectionResponse`, `NotFoundResponse`. | **Trap:** the path template MUST be `/api/v1/projects/{project}`, never `{project:key}`. `RouteUri::parse` strips the binding field out of the stored URI (`vendor/laravel/framework/src/Illuminate/Routing/RouteUri.php:41-57`), and `ApiContractTest` compares documented paths against `$route->uri()` (`tests/Feature/ApiContractTest.php:15-18`). Getting this wrong makes the contract test red with a confusing diff. The second contract assertion (`ApiContractTest.php:69-86`) also requires `security` on every non-login operation. |
| 7 | **Fix the 403 render.** The callback typed `MissingAbilityException` at `bootstrap/app.php:58-60` is dead code. Retype it on `AccessDeniedHttpException`, still scoped to `api/*`, and branch on `$e->getPrevious() instanceof MissingAbilityException` to keep the token message distinct from the resource message. | `Handler::render()` calls `prepareException()` at line 710 **before** `renderViaCallbacks()` at line 712, and `prepareException` converts any `AuthorizationException` without a status into `AccessDeniedHttpException` (`Handler.php:766`). `MissingAbilityException extends AuthorizationException` (`vendor/laravel/sanctum/src/Exceptions/MissingAbilityException.php:8`) and never sets a status (`AuthorizationException.php:22,89-92`), so by the time the callbacks run the type no longer matches. The app therefore returns English `{"message":"Invalid ability provided."}`, while `openapi/v1.json:127,165` promises Spanish. It went unnoticed because `McpServerTest.php:98,107` assert only the status code, never the body. This change adds a `Gate::authorize('view', ...)` 403 on the same broken path, so it cannot be deferred. Verified by reading framework source, **not** by execution — `sdd-spec` must land a RED test first. |
| 8 | **Add a 404 render** for `api/*`: `{"message": "Recurso no encontrado."}`. | Route-model binding failure becomes `NotFoundHttpException` carrying the Eloquent message (`Handler.php:762`), and `convertExceptionToArray` returns that message verbatim for HTTP exceptions **even with `app.debug` off** (`Handler.php:1123-1134`). Without this render, a mobile client asking for a bad key gets `No query results for model [App\Models\Project] XYZ` in production. Decision 3 makes 404 a routine, expected response, so the leak stops being theoretical. |
| 9 | `show` authorizes with the existing `ProjectPolicy::view` (membership) → 403. The list is scoped by the `projects()` relation, never by a policy filter. | Satisfies `openspec/config.yaml:38` — the same authorization as the web counterpart (`ProjectController.php:22`, `ProjectPolicy.php:22-25`) — with zero new policy code. Unreachable in practice today because `createWithDefaultColumns()` always attaches the owner (`Project.php:123`), but the guard must exist before changes 3-9 nest under it. |

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `routes/api.php` | Modified | two GETs inside the existing `['auth:sanctum','abilities:mobile']` group (lines 10-13) |
| `app/Http/Controllers/Api/V1/ProjectController.php` | New | `index`, `show` |
| `app/Http/Resources/ProjectResource.php` | New | pinned shape, `@mixin Project`, mirroring `UserResource.php:9-29` |
| `bootstrap/app.php` | Modified | retype the 403 render, add the 404 render — both `api/*` scoped |
| `openapi/v1.json` | Modified | +2 operations, +1 tag, +4 schemas |
| `tests/Feature/ApiProjectsTest.php` | New | list, show, exact shape, 404, 403, N+1 guard |
| `tests/Feature/ApiAuthTest.php` | Modified | 403 and 404 body assertions (Decisions 7-8) |
| `app/Models/Project.php`, `ProjectPolicy.php`, `routes/web.php` | Unchanged | reused as-is |
| migrations | None | no schema change |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Decision 7 is reasoned from framework source, not executed | Med | Strict TDD makes this self-correcting: the RED test asserts the documented Spanish body. If it passes on the first run the analysis was wrong and Decision 7 is dropped — cost is one test, and the assertion is worth keeping either way |
| Retyping the render to `AccessDeniedHttpException` swallows a 403 raised elsewhere | Low | Scoped to `api/*` exactly like the 401 render (`bootstrap/app.php:54-56`); web and `/mcp` fall through on `null`. Callback order is preserved so the `AuthenticationException` render still matches first (`Handler.php:809-819`) |
| The pinned resource shape proves too thin (client wants timestamps or a deep link) | Med | Additive by construction — new fields are non-breaking. This is the deliberately reversible direction; the irreversible one is leaking a column |
| No pagination becomes wrong at scale | Low | Adding a paginator only appends `links`/`meta`; `data` stays. Documented in OpenAPI so the client does not assume a closed key set |
| Deferring writes strands the mobile client | Low | The list and detail screens are fully functional without them; the web and MCP surfaces already cover writes |
| OpenAPI path template written as `{project:key}` | Med | `ApiContractTest` fails loudly in both directions (`ApiContractTest.php:52-67`); Decision 6 names the trap |

## Rollback Plan

No migrations, no schema change, no data written. Revert the branch merge; that restores
`routes/api.php` to three routes, deletes `ProjectController`/`ProjectResource`, returns
`bootstrap/app.php` to its change-1 renders, and returns `openapi/v1.json` to three operations.

Two revert consequences to state up front:

- Reverting Decision 7 restores the English `Invalid ability provided.` 403 body. **The mobile client
  must therefore branch on HTTP status codes, never on message text.** This belongs in the OpenAPI
  description so `posterMobile` never keys logic on a Spanish string.
- Reverting Decision 8 re-exposes the model FQCN on 404. Acceptable for a rollback window on a
  single-owner app, but it is the reason revert should be followed by a fix-forward, not left standing.

No token, project, or user row is mutated by this change, so there is nothing to clean up after revert
— unlike change 1, which required deleting orphan `mobile` tokens.

## Dependencies

- None. No new Composer or npm package. Builds only on `api-token-auth`, already merged.

## Delivery Forecast

Estimated **~450 changed lines** against the 400-line review budget → **400-line budget risk: Medium**.
Under `single-pr-default` this is one branch with three atomic commits:

1. Error-contract fix — `bootstrap/app.php` renders + RED tests for the 403 and 404 bodies (~70 lines).
2. Read endpoints — routes, controller, resource, `ApiProjectsTest` including the N+1 guard (~200 lines).
3. `openapi/v1.json` delta (~180 lines, JSON).

Commit 1 stands alone and is independently revertable; it fixes a published contract that is wrong
today regardless of whether commits 2-3 land. Nothing merges until the full suite is green.

## E2E Acceptance

Feature-level HTTP E2E in `tests/Feature/ApiProjectsTest.php`, following the change-1 convention
(`tests/Feature/ApiAuthTest.php:14-50`): a real `POST /api/v1/login`, then the minted bearer token
driving `GET /api/v1/projects` and `GET /api/v1/projects/{key}` — never `actingAs`, so the full
guard + ability + binding + policy chain is exercised end to end.

Browser E2E is **not** proposed. Two independent reasons: this change adds no browser surface, and the
browser suite is currently red from a pre-existing environment defect (headless browser renders blank
pages) that also affects change 1's `tests/Browser/MobileTokenRevokeFlowTest.php`. That defect is
out of scope here and must not be used as a merge gate.

## Success Criteria

- [ ] `GET /api/v1/projects` with a `mobile` token returns `{"data":[...]}` where every element has
      exactly `id`, `key`, `name`, `description`, `issues_count` — asserted with `toBe()` so an extra
      leaked column fails.
- [ ] The list contains only active projects; an archived project is absent.
- [ ] `GET /api/v1/projects/PROJ` returns that project; an unknown key and an archived key both return
      404 with `{"message":"Recurso no encontrado."}` and no model FQCN.
- [ ] Query count for the list is identical with 1 project and with 5 (no N+1 on `issues_count`).
- [ ] No token → 401 + `WWW-Authenticate: Bearer`; an `mcp`-ability token → 403 with the Spanish body
      that `openapi/v1.json` documents.
- [ ] `ApiContractTest` passes: two new operations documented, both declaring `bearerAuth`.
- [ ] `php artisan test --compact` and `npm run build` pass.

## Proposal question round

Interactive shaping was not available to this executor. These assumptions were made and are open for
correction before `sdd-spec`:

1. **Read-only is the right first slice.** Assumed the mobile client's first release is a viewer, and
   that project creation stays on the desktop. If the phone must create projects on day one, say so and
   `api-projects-write` gets pulled forward instead of deferred.
2. **`issues_count` is the only aggregate worth shipping.** Assumed a list screen shows "N issues" and
   nothing else. If the first screen also shows open-vs-done, or the active sprint, that changes the
   query and should be decided now, not bolted on.
3. **Timestamps are not needed yet.** Assumed the client refetches rather than doing delta sync. If
   offline-first sync is planned, `updated_at` should go in now — it is additive, but shipping it late
   means a client release that cannot sync.
4. **404 on an archived project is the desired client experience.** Assumed "gone means gone". The
   alternative is returning it with an `archived` flag so the client can show "this project was
   archived" instead of a bare error. Decision 3 chose the stricter behavior; it is reversible.
