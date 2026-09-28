> **D-017 lifted by Marco on 2026-09-27** (in-session: "1 pero implementa todo, lo testeas y luego yo lo
> pruebo" / "si acepto"): implementation starts now. Each `##` group is one phase: an implementer agent
> builds it, a separate adversarial tester agent verifies it, then it is committed on `feat/marcos-os-web`.
> Finish every task of a phase and run `php artisan test` and `npm run build` before the next phase.
> NOTHING merges to `main` or reaches production until Marco's final review; production steps (1.4, the
> production reset, installing the bridge daemon on the VPS, deploy) wait for his explicit OK at that time. Strict TDD: write the failing Pest test first in every
> task that changes behavior. Spanish UI copy, English identifiers, UTC-6 day math. UI follows design.md D16:
> `visual/` is the source of truth and each page's mockup is its acceptance reference.

## 1. Phase 1 — Legacy export and restore rehearsal (runs on the legacy app)

- [x] 1.1 Test + implement `marcos:export-legacy`: pg_dump custom format, one JSON per table (no password or token hashes), manifest with row counts and SHA-256; refuse output paths inside the repo (`legacy-data-export`)
- [x] 1.2 Test + implement manifest verification: exit non-zero and mark unverified on any row-count mismatch
- [x] 1.3 Test + implement `marcos:rehearse-restore <backup>`: restore into a scratch database, compare counts, record the result, drop the scratch database
- [ ] 1.4 Run export + rehearsal in production, copy the backup to the local machine, record the backup path in the change notes — **blocked: needs Marco's explicit OK (production)**; note: the app image (`Dockerfile`) has no `pg_dump`/`pg_restore`, so the commands cannot run inside the container until a PostgreSQL client matching the server's major version is available there
  > Before running in production: the production image lacks pg_dump/pg_restore and the default backup path /root/backups/posterprojects would live inside the container — needs a host-mounted volume or running the export from the host.
- [x] 1.5 Create git tag `pre-marcos-os` on the last legacy commit
- [x] 1.6 UI foundation from `visual/marcos-os-styleguide.html` (design.md D16): port the token block to Tailwind v4 CSS variables + shadcn theme (light/dark), load Overpass + Zilla Slab, radii/elevation/motion/state tokens, base components (button, input, card, check item "la marca", 2-minute chip, capture input, sheet/dialog, toast), custom glyphs; reused pages inherit the theme (mockups: visual/screens/01-login.html, 27-settings-mcp-token.html, 28-settings-mobile-qr.html, 30-password-confirmation.html)
- [x] 1.7 Test + implement Settings: appearance — theme selector claro / oscuro / sistema persisted per user (`users.appearance`, default `system`), applied on every page without a flash of the wrong theme; no profile or password editing in the web UI (`auth`) (mockup: visual/screens/29-settings-appearance-profile-password.html)
- [x] 1.8 Test + implement `marcos:set-password {email}`: prompts for the new password twice (hidden input), validates with the default password rules, stores the hash, never echoes or logs it (`auth`)

## 2. Phase 2 — Clean slate and domain core (objectives, plans, items, dependencies)

- [x] 2.1 Test + implement `marcos:reset` guards: verified + rehearsed backup younger than 24 h, confirmation phrase, preserves owner user and `mcp`/`mobile` tokens (`legacy-data-export`)
- [x] 2.2 Migrations: drop legacy tables (projects, project_members, sprints, board_columns, labels, issues, comments, issue_label); create objectives, plans, control_plans, control_map_entries, items, item_two_minute_history, item_dependencies, milestone_evidence, focus_sessions, retirements (design D2) with working `down()`
- [x] 2.3 Remove legacy models, factories, policies, controllers, form requests, Inertia pages and feature/browser tests for sprints, backlog, board, labels, comments, calendar, projects trash (specs REMOVED); keep a list of deleted tests in the PR description
- [x] 2.4 Models + factories: Objective (key, state, next_item_number with row-locked allocation), Plan, ControlPlan, ControlMapEntry, Item (derived state scope), ItemDependency
- [x] 2.5 Test + implement item number allocation per objective, never reused after retirement (`issues`: Item Numbers …)
- [x] 2.6 Test + implement the `Actor` value object and the domain action layer skeleton with `TierGate` (owner actors pass through) and audit writer (design D3)
- [x] 2.7 Test + implement 5-point plan validation and activation rules for objectives and plans, single metric, Spanish messages (`control-plan`, `projects`)
- [x] 2.8 Test + implement control map CRUD; outside-zone entries cannot become tasks (`control-plan`)
- [x] 2.9 Test + implement plan actions: create/append, reorder, auto-done when all non-retired items done, uncheck returns to active (`plans`)
- [x] 2.10 Test + implement item actions: add with required 2-minute version, edit (same-objective plan only), check, uncheck, milestone requires evidence (`issues`)
- [x] 2.11 Test + implement dependencies: add/remove, duplicate and self-edge rejection, recursive-CTE cycle rejection with path, cross-objective edges, derived locked/available (`unlock-graph`)
- [x] 2.12 Test + implement objective lifecycle (draft/active/closed/retired), navigation shows only active, no delete routes anywhere (`projects`)
- [x] 2.13 Screens: Objectives index, Objective detail (tree), Objective form, Plan detail/form, Item detail with deep links failing closed (screen inventory 3–7; `issues` deep links) (mockups: visual/screens/03-objectives-index.html, 04-objective-detail.html, 05-objective-form.html, 06-plan-detail.html, 07-item-detail.html)
- [x] 2.14 Target dates rendered neutrally, never danger color or overdue wording (`issues`)
- [x] 2.15 API cutover: unregister projects/issues/board-columns/sprints/labels routes; add `GET /api/v1/objectives`, `GET /api/v1/objectives/{objective}`, item show and check; pinned shapes; query-count tests; update `openapi/v1.json` (bump `info.version`); `ApiContractTest` green (`api-projects`, `api-issues`, `api-auth`)
- [x] 2.16 MCP cutover: remove board/backlog/sprint/label/comment/calendar/project-trash/issue tools; add list-objectives, show-objective, show-item, check-item, uncheck-item with web parity and cross-objective scoping (`mcp-server`)
- [ ] 2.17 Run `marcos:reset` in production after deploy; smoke-test login, MCP initialize, mobile habits endpoint — **blocked: needs Marco's explicit OK (production)**; tooling done and tested locally in 2.1 (`php artisan marcos:reset --owner=<email>` after a fresh `marcos:export-legacy` + `marcos:rehearse-restore`, both < 24 h)

## 3. Phase 3 — Now screen and execution

- [x] 3.1 Test + implement `StartItem`: exactly one active item (partial unique index), previous one released, focus session opened/closed (`now-focus`, design D6)
- [x] 3.2 Test + implement Now selection: active, else first available of weekly main priority, else oldest available of first active objective
- [x] 3.3 Now screen: one task, 2-minute version as primary action, unlocks list, today's habit checks row, empty state inviting capture/planning (screen 2) (mockup: visual/screens/02-now.html)
- [x] 3.4 Silent 25-minute cue from persisted `focus_started_at`: discreet inline region (3px filling line + dot + text) with `aria-live="off"` (no screen-reader announcement), three labeled keyboard-reachable options — Sigo (dismiss until next tramo), Terminé (= Marcar hecho), Estoy trabado (= stuck flow) — no sound/modal/notification/focus steal, auto-dismiss; browser test for reload continuity (design D7) (mockup: visual/screens/02-now.html)
- [x] 3.5 Test + implement "estoy trabado" fallback without AI: ask for one smaller physical action, save as new 2-minute version with history (mockup: visual/screens/02-now.html)
- [x] 3.6 Unlock animation on completion (Now mini-graph) with reduced-motion static fallback (`unlock-graph`) (mockup: visual/screens/02-now.html)
- [x] 3.7 Test + implement restart offer after inactivity without missed-day counts; auth landing redirects to Now (`now-focus`, `auth`)
- [x] 3.8 MCP `now-view` with absolute URL; update the external `foco-hoy` skill to use it (outside this repo, note only)
  > Note: `foco-hoy` (outside this repo) must call MCP `now-view` instead of reading issues: it returns `now` (the one active or suggested task with `two_minute_version`, `unlocks`, absolute `url`), `restart` and `now_url`. Not edited from this worktree.

## 4. Phase 4 — Habits adaptation

- [ ] 4.1 Migration: habits add objective_id, plan_id, two_minute_version, identity_statement, level, level_ladder; habit_days add two_minute_logged
- [ ] 4.2 Test + implement 2-minute version required on create/update; logging it counts as shown-up but never `completed` (`habits`)
- [ ] 4.3 Test + implement tolerant streak "never miss twice" for daily, weekdays and times-per-week, `streak_state` ok/at_risk/restart, best streak (design D5)
- [ ] 4.4 Test + implement identity votes per identity statement over rolling 7 and 30 UTC-6 days, inheritance from objective (`habits`)
- [ ] 4.5 Test + implement level ladder suggestions (≥80% over 14 days up, step down after break), owner-applied only
- [ ] 4.6 Test + implement objective/plan links without cascading lifecycle effects
- [ ] 4.7 Screens: Habits today (volver + 2-minute), Habits manage, Habit detail/form, Identity votes (screens 18–21) (mockups: visual/screens/18-habits-today.html, 19-habits-manage.html, 20-habit-detail.html, 21-identity-votes.html)
- [ ] 4.8 API: extend `habits/today` to the 17-field shape keeping the 12 legacy fields; add `POST /api/v1/habits/{habit}/two-minute`; OpenAPI + contract test (`api-habits`)
- [ ] 4.9 MCP: habit tools accept/return 2-minute version, streak state; add `log-two-minute`

## 5. Phase 5 — Unlock graphs

- [ ] 5.1 Test + implement graph read models: per-objective (nodes grouped by plan, external stubs) and global (clusters, cross-objective edges), retired excluded (`unlock-graph`)
- [ ] 5.2 Per-objective graph screen with add/remove dependency interactions and cycle error display (screen 8) (mockup: visual/screens/08-objective-graph.html)
- [ ] 5.3 Global graph screen with collapse per objective and Now highlight (screen 9) (mockup: visual/screens/09-global-graph.html)
- [ ] 5.4 MCP `objective-graph` and `global-graph` matching the web read models

## 6. Phase 6 — Retirement protocol and Retired view

- [ ] 6.1 Test + implement `RetireElement` for item, milestone, plan, habit, objective, capture: reason ≥10 chars, move/split/archive-as-is, atomic, dependents recomputed (`retirement`, design D8)
- [ ] 6.2 Test + implement `NotRetired` global scope on all retirable models and the hidden-everywhere rule (web, API lists, MCP lists)
- [ ] 6.3 Test + implement restore (parent must not be retired, history kept)
- [ ] 6.4 Migrate habit archive/unarchive to retire/restore across web, API 404 set and MCP (`habits`)
- [ ] 6.5 Retire flow dialog and Retired view with filters, per-reason counts and median age per kind (screens 22–23) (mockups: visual/screens/22-retired-view.html, 23-retire-flow.html)
- [ ] 6.6 MCP `retired-view`; retirement via MCP is a major operation (proposal until Phase 8 grants exist)

## 7. Phase 7 — Capture inbox and reviews

- [ ] 7.1 Migrations + models: captures, weekly_priorities, reviews
- [ ] 7.2 Test + implement capture (one field, source, never on Now) and global quick-entry overlay with shortcut (`capture-inbox`, screens 16–17) (mockups: visual/screens/16-capture-inbox.html, 17-capture-quick-entry.html)
- [ ] 7.3 Test + implement triage: convert to item/habit/objective draft or retire, link kept (mockup: visual/screens/16-capture-inbox.html)
- [ ] 7.4 Test + implement weekly priority (1 main + ≤2 maintenance) feeding Now selection (`reviews`)
- [ ] 7.5 Weekly review screen: progress first, then questions; skipping blocks nothing (screen 14) (mockup: visual/screens/14-weekly-review.html)
- [ ] 7.6 Test + implement milestone summit with evidence text/link/image (≤5 MB, images only) (screen 12) (mockup: visual/screens/12-milestone-summit.html)
- [ ] 7.7 Test + implement objective close: learning review + keep/retire decision per linked habit, saved atomically (screen 13) (mockup: visual/screens/13-objective-close.html)
- [ ] 7.8 Reviews history screen (screen 15); MCP `capture` and `list-inbox` (mockup: visual/screens/15-reviews-history.html)

## 8. Phase 8 — AI operations (tiers, proposals, grants, audit)

- [ ] 8.1 Migrations + models: ai_proposals, ai_permission_grants, ai_audit_entries
- [ ] 8.2 Test + implement `TierGate` classification for AI actors (minor list; everything else major) (`ai-operations`, design D11)
- [ ] 8.3 Test + implement proposals: create, accept (exact), edit-then-accept, reject, 7-day expiry, stale target fingerprint fails safely
- [ ] 8.4 Test + implement permission grants: password-confirmed creation, scope, TTL default 60 min / max 24 h, single active, immediate revoke (`auth`)
- [ ] 8.5 Test + implement audit entries for every AI-applied change with before/after
- [ ] 8.6 MCP: `propose-change`, `list-proposals`, `apply-change` (grant-gated), `shrink-step` (minor), `progress-summary`; tier stated in every tool description (`mcp-server`)
- [ ] 8.7 Screens: AI proposals, AI activity & grant (screens 24–25) (mockups: visual/screens/24-ai-proposals.html, 25-ai-activity-grant.html)

## 9. Phase 9 — AI tree negotiation

- [ ] 9.1 Migrations + models: negotiations, negotiation_rounds; drafts never visible in domain queries
- [ ] 9.2 Test + implement one-layer-per-round rule and round recording (`tree-negotiation`)
- [ ] 9.3 Test + implement `PersistNegotiation`: requires owner accept + AI agreed, atomic, errors mapped to draft paths
- [ ] 9.4 MCP `show-negotiation` and `propose-tree-layer`
- [ ] 9.5 Screens: Tree negotiation editor and Negotiations list with pause/abandon (screens 10–11) (mockups: visual/screens/10-tree-negotiation.html, 11-negotiations-list.html)

## 10. Phase 10 — Web → VPS CLI bridge

- [ ] 10.1 Migration + model `ai_bridge_runs`; `AI_BRIDGE_ENABLED` kill switch config (`ai-cli-bridge`)
- [ ] 10.2 Test + implement run creation: owner session, CSRF, password confirmed < 15 min, template allowlist, rate 20/h, max 3 queued, 202 with run id; unreachable from API/MCP tokens
- [ ] 10.3 Test + implement `DispatchBridgeRun` job on a single-worker queue with HMAC-signed requests (timestamp, nonce), timeout handling, 64 KB truncation, audit
- [ ] 10.4 Host service `marcos-bridge` (location/language approved by Marco first): unix socket only, signature/timestamp/nonce checks, template → fixed argv, stdin text, MCP-only allowed tools, 300 s timeout (design D10)
- [ ] 10.5 Provision on the VPS: systemd unit, socket bind-mount into the Coolify app, dedicated `mcp` token, shared secret; verify kill switch both sides
- [ ] 10.6 Bridge run screen with polling, cancel and output; wire "estoy trabado" and negotiation "pedir capa" to the bridge when enabled (screen 26) (mockup: visual/screens/26-ai-bridge-run.html)
- [ ] 10.7 Security test pass: shell metacharacters as data, replayed nonce rejected, unknown template 422, token cannot trigger

## 11. Phase 11 — API v1 daily endpoints for `marcos-os-mobile`

- [ ] 11.1 Test + implement `GET /api/v1/progress` (UTC-6 yesterday and this week, votes, streaks, no debt) (`api-daily`)
  - [ ] 11.1.1 Test + implement the per-day `days` array (Monday of the current ISO week through today, America/Guatemala dates, `items_done`, `habits_done`, `habits_scheduled`, `shown_up`), query count independent of the number of days (`api-daily`)
- [ ] 11.2 Test + implement `GET /api/v1/now` matching the web Now selection, including `is_active` and `focus_started_at` (`api-daily`)
- [ ] 11.3 Test + implement `POST /api/v1/captures` (source `mobile`)
- [ ] 11.4 Migration + model `idempotency_keys`, `Idempotent` middleware (24 h window, replay header, 409 in-flight, 422 on body mismatch, no storage on 5xx) and scheduled pruning; tests (design D12b)
- [ ] 11.5 Apply `Idempotent` to captures, habit increment/decrement/two-minute and item check/start/two-minute; test that a replayed habit tap applies once and to the first request's UTC-6 day (`api-habits`, `api-daily`)
- [ ] 11.6 Test + implement `POST /api/v1/objectives/{objective}/items/{item}/start` via `StartItem`, answering the `/now` shape; 422 for locked/done (`api-issues`)
- [ ] 11.7 Test + implement `POST /api/v1/objectives/{objective}/items/{item}/two-minute` with history kept; 422 for empty text or done item (`api-issues`)
- [ ] 11.8 Query-count tests, `Daily` tag in `openapi/v1.json`, contract test green (document `Idempotency-Key`, the 24 h window and `Idempotent-Replayed`)
- [ ] 11.9 Write the API handoff notes for `marcos-os-mobile` (endpoint list, shapes, breaking changes) inside the change folder
- [ ] 11.10 Final verification: full `php artisan test`, browser suite, `npm run build`; then archive (warn: destructive deltas remove 8 capabilities)
