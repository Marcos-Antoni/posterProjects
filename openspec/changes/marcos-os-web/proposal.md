## Why

posterProjects is a JIRA-style tracker (sprints, backlog, board, labels, comments, calendar) plus a habit
tracker. Its owner does not need to *manage* work; he needs to START and FINISH one small step at a time,
including on low-energy days, without the "reset everything at the first failure" pattern. The validated
Marcos OS requirements (2026-09-27, 23 confirmed items grounded in *Control* and *Atomic Habits*) turn the
app into a single-user personal system: Objective → Plans → Milestones + Tasks + Habits, with unlock
graphs, a single "Now" task, a tolerant consistency model, a no-delete retirement protocol, and AI that
acts on minor things and proposes major ones. The web goes first; posterMobile follows in its own change
(`marcos-os-mobile`).

## What Changes

- **BREAKING** Retire the JIRA surface from web, MCP and API v1: sprints, backlog, board and columns,
  labels, comments, calendar (specs, routes, tools, endpoints, tables).
- **BREAKING** Projects become **Objectives** (single owner, no membership, no trash, no force delete).
  Issues become **Tasks** and **Milestones** grouped under **Plans**; the issue type/priority enums,
  one-level parent hierarchy and drag ordering are removed.
- Add the **Control 5-point plan** (outcome, deadline, ONE metric, what can go wrong, contingency) and a
  **control map** (mine / influence / not mine) on every objective and plan.
- Add a mandatory **2-minute version** on every task, milestone and habit, and progressive
  (Goldilocks) scaling of plans and habits.
- Add **dependencies** ("completing A unlocks B"), DAG-enforced, including cross-objective edges;
  a **per-objective graph** and a **global graph**.
- Add the **Now** screen: exactly one active task, starting with its 2-minute version, a silent
  non-interrupting ~25-minute cue, an "I'm stuck" action, size-scaled celebration, no red overdue,
  missed days restart with a 2-minute entry.
- Add the **retirement protocol**: nothing is deleted; retiring any element requires a written reason and
  a content decision (move / split / archive as-is); retired elements are hidden and visible only in a
  **Retired** view built to surface patterns.
- Modify **habits**: may hang from an objective/plan and survive its closure; **tolerant streak**
  ("never miss twice"); **identity votes** proportion; retirement replaces bare archiving.
- Add **capture inbox** (capture ≠ priority) and **reviews** (weekly priority: one main + max two
  maintenance standards; milestone "summit" with evidence; objective learning review with the
  "which habits continue?" decision).
- Add **AI operations** tiers: minor actions applied directly, major actions become proposals, major
  edits applied by AI only under an explicit, scoped, expiring permission grant from Marco.
- Add **AI tree negotiation**: AI proposes the first layer, Marco edits, iterate; nothing is persisted
  to the domain until both accept.
- Add the **web → VPS CLI bridge** (Claude Code on the VPS triggered from the web): owner-only,
  password-reconfirmed, allowlisted command templates, signed requests, concurrency 1, rate-limited,
  async with status polling, audited, kill switch.
- **BREAKING** API v1: `/projects`, `/issues`, `/board-columns`, `/sprints`, `/labels` are replaced by
  `/objectives` and item endpoints; habits endpoints keep their paths with an extended pinned shape; new
  daily endpoints (progress summary, next task with focus clock, capture, check, start, replace 2-minute
  version) with `Idempotency-Key` support on mobile writes (24 h window) prepare `marcos-os-mobile`.
- **BREAKING** MCP: board/backlog/sprint/label/comment/calendar/project-trash/issue tools are replaced by
  objective, item, graph, now, capture, retirement, proposal and habit tools. The `foco-hoy` skill must
  switch from issue tools to `now-view`.
- **BREAKING (data)** Export a verified backup of all current data, then start from a clean database.
- Full visual redesign. Visual design system: see design proposal (separate).

## Capabilities

### New Capabilities
- `control-plan`: 5-point plan and control map on objectives and plans; progressive (Goldilocks) scaling.
- `plans`: Plans as the middle layer between an objective and its milestones/tasks/habits.
- `unlock-graph`: dependencies between tasks/milestones, DAG rules, per-objective and global graphs.
- `now-focus`: the Now screen, one active task, 2-minute start, silent cue, "I'm stuck", celebration
  by size, no-punishment and anti-engagement rules.
- `retirement`: no-delete retirement protocol for every element kind and the Retired view.
- `capture-inbox`: quick capture and triage into the tree.
- `reviews`: weekly priority, milestone summit with evidence, objective close and learning review.
- `ai-operations`: minor/major tiers, proposals, permission grants, audit trail (shared by MCP and bridge).
- `tree-negotiation`: AI-proposed, owner-edited draft trees persisted only on mutual acceptance.
- `ai-cli-bridge`: owner-triggered web → VPS CLI execution with strict security and async UX.
- `api-daily`: API v1 endpoints for the mobile daily loop (progress summary, next task, capture, check).
- `legacy-data-export`: verified export of all current data and the guarded clean-slate reset.

### Modified Capabilities
- `projects`: becomes the Objectives capability (keyed, single owner, lifecycle active/closed/retired).
- `issues`: becomes Tasks and Milestones under plans (kind, 2-minute version, checkable, evidence).
- `habits`: link to objective/plan, tolerant streak, identity votes, 2-minute version, retirement.
- `auth`: authenticated landing becomes the Now screen; password reconfirmation guards the AI bridge;
  per-user appearance preference (claro / oscuro / sistema); password changes only via an artisan command.
- `api-projects`: `/api/v1/projects` replaced by `/api/v1/objectives`.
- `api-issues`: `/api/v1/projects/{project}/issues` replaced by objective item endpoints.
- `api-habits`: pinned today shape extended (2-minute version, tolerant streak state, objective link).
- `api-auth`: OpenAPI contract covers the new surface; removed routes are unregistered.
- `mcp-server`: tool set replaced; authorization, scoping and parity rules restated for the new domain
  and the AI tiers.
- `sprints`, `backlog`, `board`, `labels`, `comments`, `calendar`, `api-board-sprints`, `api-labels`:
  all requirements REMOVED (feature retired from the product).

## Requirements traceability (validated requirements R1–R23)

| R | Covered by | R | Covered by |
|---|---|---|---|
| 1 | projects, plans, issues | 13 | retirement |
| 2 | habits, reviews | 14 | retirement (Retired view) |
| 3 | control-plan | 15 | api-daily (web side); mobile screen **scoped out** → `marcos-os-mobile` |
| 4 | control-plan, issues, habits | 16 | design.md surface split; web screens here, mobile screens **scoped out** |
| 5 | unlock-graph | 17 | ai-operations, mcp-server |
| 6 | unlock-graph | 18 | (a) mcp-server + ai-operations (CLI already runs on VPS; no app code beyond MCP); (b) ai-cli-bridge |
| 7 | tree-negotiation | 19 | projects (no membership), no sharing anywhere; design.md |
| 8 | now-focus | 20 | all REMOVED/MODIFIED deltas; auth/QR/deploy reused unchanged |
| 9 | now-focus, ai-operations | 21 | legacy-data-export |
| 10 | now-focus, reviews | 22 | timeline below; mobile **scoped out** to `marcos-os-mobile` |
| 11 | habits | 23 | design.md (screen inventory, UX behavior); visuals in separate design proposal |
| 12 | now-focus, habits | — | "Also captured": capture-inbox, reviews (weekly priority), now-focus (anti-patterns) |

## Impact

- **Code**: `app/Models` (Project, Issue, Sprint, BoardColumn, Label, Comment replaced/removed; new
  Objective, Plan, Item, Dependency, Retirement, Capture, Review, WeeklyPriority, AiProposal,
  AiPermissionGrant, AiBridgeRun, NegotiationDraft, FocusSession), controllers, form requests, policies,
  `resources/js/pages/**` (full redesign), `routes/web.php`, `routes/api.php`, `app/Mcp/**`,
  `openapi/v1.json`, migrations (new baseline), factories, `tests/Feature/**`, `tests/Browser/**`.
- **API consumers**: posterMobile's project/issue/board/sprint/label screens stop working at the Phase 2
  cutover; habits keep working (additive shape). posterMobile is adapted in `marcos-os-mobile`.
- **MCP consumers**: Claude Desktop/Code integrations and Marco's skills (`foco-hoy` reads issues) break at
  the Phase 2 cutover and must move to the new tools.
- **Infrastructure**: a host-side bridge daemon on the VPS (outside Coolify's container), a shared HMAC
  secret, a queue worker for async bridge runs. Coolify auto-deploy of `main` is unchanged.
- **Data**: destructive reset after a verified backup. The backup and reset target ONLY the Coolify
  application `posterprojects` (uuid `cjxh8mj4s8fppb0mv5bt5vht`, `https://posterprojects.marcobarrera.cloud`,
  the one the `poster` MCP talks to).
- **Legacy Node stack (out of scope)**: `/root/proyectos/posterProjects` on the VPS (pnpm/turbo monorepo,
  branch `feat/T-8-detalle-issue`) still runs 8 containers (`posterprojects-{dev,prod}-{web,api,mcp}` plus
  two Postgres). Marco decided (2026-09-27) to leave it running untouched while it does not block this
  change. No task may stop, back up, migrate or delete it; if it ever blocks a phase, stop and ask Marco.

## Timeline

- **2026-09-27**: planning done. Decision **D-017** originally froze implementation until 2026-10-11; Marco
  explicitly lifted it on 2026-09-27 and accepted reviewing the code at the end (exception to his
  comprehension gate, for this change only). To be recorded as D-018 in the Marcos OS repo.
- **From 2026-09-27**: phases in `tasks.md`, in order, built by an implementer agent and verified by a
  separate adversarial tester agent, committed on `feat/marcos-os-web`. No merge to `main` and no
  production step until Marco's final review and explicit OK.
- **After Phase 11 ships**: `marcos-os-mobile` (posterMobile) starts, not in parallel (R22).

## Rollback plan

- **Phase 1 (export)** is additive; rollback = revert the commit.
- **Before Phase 2** a git tag `pre-marcos-os` marks the last legacy commit, and the Phase 1 backup MUST
  have passed a restore rehearsal into a scratch database. Rollback of Phase 2 = revert `main` to
  `pre-marcos-os` (Coolify redeploys it), drop the new schema, `pg_restore` the verified dump, re-run the
  legacy test suite smoke checks. MCP and mobile tokens survive because `personal_access_tokens` and
  `users` are part of the dump.
- **Phases 3–11** are additive on the new schema; each ships behind its own migrations with working
  `down()` methods; rollback = revert the phase's merge commit and roll back its migrations.
- **AI bridge (Phase 10)** has a runtime kill switch (`AI_BRIDGE_ENABLED=false`) and the host daemon can
  be stopped independently; no data rollback is needed.

## Open questions (defaults chosen; Marco may override)

1. Removed API/MCP routes: **unregistered** (generic 404) rather than a `410 Gone` transition window.
2. Capability folders `projects` / `issues` keep their paths in this change; renaming to `objectives` /
   `tasks` is a housekeeping follow-up after archive.
3. Identity votes = completed opportunities ÷ scheduled opportunities per identity statement over rolling
   7 and 30 UTC-6 days, shown as a proportion, never a score.
4. Tolerant streak for times-per-week habits breaks only after **two consecutive** weeks under quota.
5. The ~25-minute cue interval is fixed at 25 minutes (config value, no UI setting).
6. Bridge supports Claude Code first; Codex is a second allowlisted runner added later.
7. Permission grant defaults: scoped to one objective or "global", TTL 60 minutes, revocable, single
   active grant.
8. Legacy habit history is **not** re-imported (clean DB per R21); the backup keeps it available.
9. Retired elements MAY be restored; the retirement record is kept as history.
10. Backup location: VPS `/root/backups/posterprojects/` plus a local copy; never in git.
11. Mobile gap: between the Phase 2 cutover and `marcos-os-mobile`, posterMobile keeps only habits.
