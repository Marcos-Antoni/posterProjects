# marcos-os-web — Coordination LOG

Single shared coordination file for every agent working on this change (implementers, adversarial
testers, integration testers, coordinator). **Canonical path — always read and write THIS file by its
absolute path, even from a worktree:**

`/home/marco/proyectos/personal/posterProjects/openspec/changes/marcos-os-web/LOG.md`

(Each worktree has its own git copy of this file; ignore that copy. Only the canonical path above is live.
The coordinator commits it.)

## 1. Read before you start (mandatory)

1. This whole file — rules, assignments, and every log entry of the phases in your wave and earlier waves.
2. `tasks.md` header, `proposal.md` Timeline, `design.md` (D-sections your phase touches + D16 visual).
3. Your phase's tasks and specs; the mockups your tasks reference (`visual/screens/NN-*.html`).

## 2. Roles

| Role | Does | Never |
|---|---|---|
| Implementer (phase N) | Builds phase N tasks via the opsx apply workflow, strict TDD, in its own worktree | Edits other phases' tasks, tester files, specs, `visual/`; commits; merges; touches `main`/prod |
| Adversarial tester (phase N) | Distrusts the implementer; writes its own acceptance tests under `tests/{Feature,Browser}/Acceptance/PhaseN/`; compares UI to mockups; returns `VEREDICTO: APROBADO/RECHAZADO` | Edits application code or the implementer's tests; softens findings |
| Integration tester (per wave) | After the coordinator merges a wave, runs the full suite + cross-phase checks on the merged branch | Edits application code |
| Coordinator | Creates worktrees, commits approved phases, merges waves into `feat/marcos-os-web`, resolves conflicts, reports to Marco | Pushes to `main`, touches production |

Rejection loop: implementer ↔ tester until APROBADO. After 3 rejected rounds on the same defect, write a
`DISPUTE` entry and stop; the coordinator escalates to Marco.

## 3. Waves and worktrees

| Wave | Phases (parallel) | Starts after |
|---|---|---|
| 0 | 2 | Phase 1 (done, 6b97a60) |
| A | 3 Now · 4 Habits · 5 Graphs · 6 Retirement | Phase 2 committed |
| B | 7 Capture & reviews · 8 AI operations | Wave A merged + integration APROBADO |
| C | 9 Tree negotiation · 10 CLI bridge · 11 API daily | Wave B merged + integration APROBADO |

Worktree per phase: `/home/marco/proyectos/personal/posterProjects-wt/phase-N` on branch
`feat/marcos-os-web-pN`, created by the coordinator from the tip of `feat/marcos-os-web`. Phase 2 runs in
the main checkout.

## 4. Resource assignments (avoid collisions)

| Phase | Test DB (`DB_DATABASE`) | Dev server port | Migration filename prefix |
|---|---|---|---|
| 2 | `postgres` (main checkout) | 8002 | `2026_09_28_1000xx` (existing) |
| 3 | `marcos_p3` | 8003 | `2026_09_28_3000xx` |
| 4 | `marcos_p4` | 8004 | `2026_09_28_4000xx` |
| 5 | `marcos_p5` | 8005 | `2026_09_28_5000xx` |
| 6 | `marcos_p6` | 8006 | `2026_09_28_6000xx` |
| 7 | `marcos_p7` | 8007 | `2026_09_28_7000xx` |
| 8 | `marcos_p8` | 8008 | `2026_09_28_8000xx` |
| 9 | `marcos_p9` | 8009 | `2026_09_28_9000xx` |
| 10 | `marcos_p10` | 8010 | `2026_09_29_1000xx` |
| 11 | `marcos_p11` | 8011 | `2026_09_29_1100xx` |

Run tests with the phase DB: `DB_DATABASE=marcos_pN php artisan test --compact` (phpunit.xml does not
force `DB_DATABASE`, so the shell value wins). Create the DB once on the local Postgres used by
phpunit.xml (127.0.0.1:34146) if it doesn't exist. Browser tests / `php artisan serve` use the assigned
port only. Screenshots go to the session scratchpad under `shots/phaseN/`.

## 5. Shared "hot" files — rules

These files are touched by several phases; follow the rule exactly so merges stay mechanical.

| File / area | Rule |
|---|---|
| `routes/web.php`, `routes/api.php` | Add your routes in ONE contiguous block wrapped by `// --- phase N: <topic> ---` / `// --- end phase N ---`, placed at the end of the relevant group. Never reorder or reformat other lines. |
| Sidebar / navigation items | Add only your entry, in the position the mockup shows, inside a `{/* phase N */}` marked line. Don't restyle the nav. |
| MCP server tool registration (`app/Mcp/**` server class) | Append your tools in a marked block; never rename/remove another phase's tools. |
| `openapi/v1.json` | Only phases 3, 4, 7, 11 (and 2 for removals) edit it; add/modify only your paths; keep keys sorted as the file already is. |
| `tasks.md` | Check ONLY your own phase's boxes. Never edit another phase's lines or the header. |
| `app/Actions/**`, models from phase 2 | Phases ≥3 may ADD methods/scopes/relations; changing an existing phase-2 signature requires a `CONTRACT` entry here first, with the reason. |
| Migrations | Only your prefix. Never edit a migration from another phase; add a new one. |
| `resources/css/app.css` tokens, base UI components (phase 1) | Do not change tokens. New variants go in your own component files. |
| `composer.json`, `package.json`, lockfiles | Frozen. No new dependencies. |
| `.atl/.skill-registry.cache.json` | Not ours. Never touch. |

## 6. How to write to this log

Append-only, never edit or delete existing lines. Append with the shell so concurrent writers don't
clobber each other, one entry per call:

```bash
printf '%s\n' "- [$(date -u +%FT%TZ)] [P<N>][<role>] <TYPE>: <one or two lines>" \
  >> /home/marco/proyectos/personal/posterProjects/openspec/changes/marcos-os-web/LOG.md
```

`<role>` = impl | test | integ | coord. `<TYPE>` is one of:

- `START` — you began; list the hot files you expect to touch.
- `TOUCH` — you are about to modify a hot file not listed in START.
- `CONTRACT` — you changed/added something other phases may rely on (route name, action signature, model scope, API field, component prop). Say exactly what.
- `NEED` — you depend on something another phase owns; name the phase and the thing. Don't implement it yourself; stub behind an interface only if the spec allows and say so.
- `DONE` — implementer finished (tasks done, test counts) / tester verdict.
- `REJECT` — tester: defect ids with severity (details stay in the tester report).
- `DISPUTE` — 3 rounds without agreement; stop.
- `BLOCKED` — production or Marco-only step; left unchecked.

Before finishing, re-read the log for new `CONTRACT` / `NEED` entries that affect you.

## 7. Log

- [2026-09-28T00:00:00Z] [P1][coord] DONE: Phase 1 committed as 6b97a60 (tester APROBADO round 2, 666/666). 1.4 BLOCKED (production; image lacks pg_dump, backup path must be host-mounted).
- [2026-09-28T00:00:00Z] [P2][coord] START: Phase 2 implementer running in the main checkout (branch feat/marcos-os-web). Waves A–C start after Phase 2 is committed.
- [2026-09-28T03:48:43Z] [P2][impl] START: hot files touched: routes/web.php, routes/api.php, app/Mcp/Servers/PosterServer.php, openapi/v1.json (removals + objectives/items), app/Http/Middleware/HandleInertiaRequests.php (navigationObjectives), app/Providers/AppServiceProvider.php ({objective} binder, morph map, AuditWriter binding), resources/js/layouts/app-layout.tsx (app shell), tests/Pest.php (mos* helpers).
- [2026-09-28T04:10:47Z] [P2][impl] CONTRACT: Models (app/Models): Objective(user_id,key[2-10 A-Z, getRouteKeyName=key],title,identity_statement,state ObjectiveState,position,next_item_number,closed_at; rel user, plans()[ordered position,id], items, controlPlan morphOne, controlMapEntries morphMany; scopes active() [state=active + ORDER position,id], withProgress() [progress_done/progress_total over non-retired items]; allocateNextItemNumber():int row-locked, never reused; static visibleForOwner(User,key):?self [active|closed only]; static nextPositionFor(User)).
- [2026-09-28T04:10:47Z] [P2][impl] CONTRACT: Plan(objective_id,title,state PlanState,level ?int,position; rel objective, items()[ordered], controlPlan, controlMapEntries; resolveRouteBindingQuery excludes retired; static nextPositionIn(Objective)). ControlPlan(plannable morph, outcome, deadline date, metric_name, metric_target, metric_current, risks list<string>, contingency; missingPoints():array<point,msg>, isComplete(), metricProgressPercent(); const MISSING_MESSAGES). ControlMapEntry(plannable morph, zone ControlZone, text, position; owningObjective()).
- [2026-09-28T04:10:47Z] [P2][impl] CONTRACT: Item(objective_id,plan_id,number,kind ItemKind,title,description,two_minute_version,target_date,is_active bool,completed_at,retired_at,position; accessors key ('KEY-N', needs objective relation) and state (ItemState: uses selected derived_state else deriveState()); scope withState() selects derived_state with ONE EXISTS subquery (precedence retired>done>active>locked>available; retired prerequisites never block); deriveState(); hasOpenPrerequisite(); rel prerequisites()/dependents() belongsToMany via item_dependencies, twoMinuteHistory() newest first, evidence() hasOne MilestoneEvidence, focusSessions(); static resolveByKey(Objective,key):?self [null for malformed/foreign/unknown/retired]; static resolveForOwner(User,key); static nextPositionIn(Plan)). ItemDependency(prerequisite_id,dependent_id). ItemTwoMinuteHistory(text=REPLACED version, source TwoMinuteSource owner|ai = who replaced it, replaced_at; table item_two_minute_history, no timestamps). MilestoneEvidence(item_id unique,text,link,image_path). FocusSession(item_id,started_at,ended_at,end_reason). Table retirements exists (morph retirable, reason, decision, decision_payload json, prior_state, retired_at, restored_at) — NO model yet (Phase 6). Morph map (non-enforced): objective, plan, item.
- [2026-09-28T04:10:47Z] [P2][impl] CONTRACT: Enums: ObjectiveState draft|active|closed|retired (canTransitionTo, label); PlanState draft|active|done|retired; ItemKind task|milestone; ItemState locked|available|active|done|retired; ControlZone mine|influence|outside (canBecomeTask); TwoMinuteSource owner|ai. Action layer App\Actions\Support: Actor(kind ActorKind, user) ::ownerWeb/ownerApi/aiMcp/aiBridge, isAi/isOwner; ActorKind owner-web|owner-api|ai-mcp|ai-bridge; Operation enum (+tier(): AiTier minor|major; minor = CheckItem, UncheckItem, UpdateMetricCurrent; Phase 3/4/7/8 ADD cases, e.g. StartItem/ShrinkStep/LogHabitEntry/Capture); TierGate::authorize(Actor,Operation) (owner passes; AI minor passes; AI major throws MajorOperationRequiresProposal — Phase 8 replaces with proposals/grants); AuditWriter interface record(Actor,Operation,Model target,array before,array after) bound to LogAuditWriter (Phase 8 rebinds to DB writer in AppServiceProvider::register); DomainTransaction::run(Actor,Operation,Model target, Closure change) = gate + DB transaction + audit for AI; GuardsObjectives trait (ensureOwned/ensureWritable/ensurePlanWritable/ensureItemWritable); ControlPlanWriter; PlanStateRecalculator::recalculate(Plan); Reorderer::move.
- [2026-09-28T04:10:47Z] [P2][impl] CONTRACT: Actions (all __invoke(Actor $actor, ...)): Objectives\CreateObjective(array), UpdateObjective(Objective,array), ActivateObjective(Objective), ReopenObjective(Objective); Plans\CreatePlan(Objective,array[title,level,activate,5 points,control_map]), UpdatePlan(Plan,array), ActivatePlan(Plan), MovePlan(Plan,int dir -1|+1); ControlMap\AddControlMapEntry(Objective|Plan,ControlZone,string), UpdateControlMapEntry(entry,array), RemoveControlMapEntry(entry), ConvertControlMapEntry(entry,Plan,array); Items\AddItem(Plan,array[kind,title,two_minute_version,description,target_date,prerequisite_ids]) const MISSING_TWO_MINUTE, UpdateItem(Item,array,TwoMinuteSource $source=Owner) [records history on 2-min change — Phase 3 'estoy trabado' can call it with source], MoveItem(Item,int), CheckItem(Item,?evidence,?link):CheckResult{item,unlocked} [closes open focus session end_reason 'done', is_active=false], UncheckItem(Item) [relocks dependents, active dependent -> is_active=false + session end_reason 'relocked'], AddDependency(Item prerequisite,Item dependent) [cycle via recursive CTE, message 'No se puede: armaría un círculo. Camino: KEY Title → …'], RemoveDependency(prereq,dependent). Closed objectives read-only (ValidationException key 'objective'). NOTE: is_active partial unique index NOT created (left to 3.1 StartItem).
- [2026-09-28T04:10:47Z] [P2][impl] CONTRACT: Read models (app/Http/Resources): ObjectiveTree::plans(Objective): list<TreePlanRow> / ::item() / ::prerequisitesOf(ids) / ::controlPlan(); ItemDetails::load(Item) + ::present(Item) (pinned API item shape) + ::neighbours(); ObjectiveResource (pinned objective shape; needs withProgress + controlPlan). Route binder: {objective} = KEY resolved in AppServiceProvider among auth user's active|closed objectives (web AND api), 404 otherwise. Shared Inertia props: navigationObjectives [{key,title}] (active only, manual order); flash.unlocked [{key,title}].
- [2026-09-28T04:10:47Z] [P2][impl] CONTRACT: Web routes (block '// --- phase 2 ---' in routes/web.php): objectives.index|create|store|show|edit|update|activate|reopen; objectives.control-map.store|update|destroy|convert; objectives.plans.create|store|show|edit|update|activate|move (+ objectives.plans.items.store, objectives.plans.control-map.store|update|destroy); objectives.items.show|update|check|uncheck|move ({item}=public key); objectives.items.prerequisites.store|destroy, objectives.items.unlocks.store|destroy. Root and login land on objectives.index (Phase 3 changes to Now). API (block in routes/api.php): api.v1.objectives.index|show, api.v1.objectives.items.show|check (openapi info.version 2.0.0, tags Objectives/Items). MCP (marked block in PosterServer): list-objectives, show-objective, show-item, check-item, uncheck-item; descriptions start 'Nivel IA: read|minor'.
- [2026-09-28T04:10:47Z] [P2][impl] CONTRACT: Frontend: AppLayout is now the mockups' appbar (sidebar removed): NAV array in resources/js/layouts/app-layout.tsx, one entry per line with /* phase N */ comment — add Ahora/Mapa/Revisiones/Retirados/IA there; right side = CommandPalette (Ctrl K, lists navigationObjectives + logout) + Ajustes link. Mockup CSS ported to resources/css/marcos-screens.css (imported in app.css, layer components): shared shell classes under .mos, per-screen under .mos-sNN (generated from visual/screens; add your screen's section as .mos-sNN). Components: components/marcos/{state-glyph,item-row,crumbs,icons,control-plan-card,control-map-card,control-plan-fields,item-mini-map,command-palette}.tsx; lib/marcos.ts (Guatemala date helpers). Factories: Objective (draft/closed/retired/withControlPlan), Plan (draft/withControlPlan), Item (milestone/done/active/retired), ControlPlan (incomplete), ControlMapEntry. Test helpers in tests/Pest.php: mosObjectiveData, mosErrors, mosOwner, mosMobileHeaders, mosQueryCount.
- [2026-09-28T04:10:47Z] [P2][impl] NEED: [P1][test] tests/Feature/Acceptance/Phase1/AuthAcceptanceTest.php dataset of 'a stored oscuro is rendered dark … on several pages' still includes '/projects', which Phase 2 retires (projects → objectives spec; route now 404). Tester please swap it for '/objectives'. It is the ONLY failing test of the full suite (542/543).
- [2026-09-28T04:10:47Z] [P2][impl] BLOCKED: 2.17 run marcos:reset in production + smoke tests — needs Marco's explicit OK (production). Tooling tested locally (2.1). Legacy tables are dropped ONLY by the reset (migration in database/migrations/marcos-reset, not the default path), per design D1.
- [2026-09-28T04:11:19Z] [P2][impl] DONE: 2.1–2.16 done, 2.17 BLOCKED (production). Full suite 543: 542 pass, 1 fail = Phase 1 acceptance dataset '/projects' (see NEED). New Phase 2 tests: 208 feature (tests/Feature/MarcosOs) + 5 browser (tests/Browser/ObjectiveScreensFlowTest.php). Pint ok, Larastan only the 5 pre-existing, eslint/tsc ok, npm run build ok. Deleted 39 legacy test files (specs REMOVED: sprints, backlog, board, labels, comments, calendar, api-board-sprints, api-labels + REMOVED reqs of projects/issues/api-projects/api-issues/mcp-server). Not committed.
- [2026-09-28T04:12:02Z] [P2][test] START: adversarial tester Phase 2 round 1. Will touch only tests/Feature/Acceptance/Phase2/, tests/Browser/Acceptance/Phase2/, and tests/Feature/Acceptance/Phase1/ (swap /projects -> /objectives per NEED). Port 8002.
- [2026-09-28T04:26:16Z] [P2][test] DONE: VEREDICTO APROBADO (round 1). 0 bloqueante, 0 importante, 4 menor (P2-T1 reset accepts future-dated backup — skipped test in ResetAndSchemaAcceptanceTest; P2-T2 drop migration prefix 110000 outside 1000xx; P2-T3 prettier check fails on marcos-screens.css; P2-T4 small visual gaps on 03/04/06). Acceptance: tests/Feature/Acceptance/Phase2 (169: 168 pass, 1 skip) + tests/Browser/Acceptance/Phase2 (6/6). Updated Phase1 AuthAcceptanceTest dataset /projects -> /objectives per NEED. Full suite 717 pass + 1 skip. Note: .atl cache dirty since 2026-07-23 (not Phase 2) — do not commit it.
- [2026-09-28T04:29:57Z] [P2][impl] DONE (round 2): P2-T1 marcos:reset rejects manifest created_at > now+5 min ('fecha futura'), skip removed in Acceptance/Phase2 test (passes) + 3 own tests; P2-T2 reset migration renamed database/migrations/marcos-reset/2026_09_28_100010_drop_legacy_jira_tables.php; P2-T3 prettier clean; P2-T4 screen 04 parallel rows indented (.irow.par, 'En paralelo con X y Y'), draft level plan 'Borrador de nivel: se prepara cuando <nivel anterior> llegue al 80 %.', screen 06 next draft rung dashed ', se sugiere al 80 %', screen 03 line falls back to outcome. composer dump-autoload done. Full suite 721/721, pint ok, larastan 5 baseline, eslint/tsc/prettier ok, build ok. Not committed.
- [2026-09-28T06:11:29Z] [P2][test] START: round 2 re-verification of P2-T1..T4.
- [2026-09-28T06:13:39Z] [P2][test] REJECT: round 2. P2-T2/T3/T4 verified fixed. P2-T1 importante: marcos:reset treats manifest created_at "" as now (Carbon::parse('')) and runs the reset — freshness guard fails open (failing test ResetAndSchemaAcceptanceTest 'missing or unparseable created_at' dataset empty). P2-T5 menor: unparseable created_at escapes as InvalidFormatException instead of a clean refusal (aborts before touching data). P2-T6 menor (process): implementer edited tester file (removed skip) — testers own acceptance files. Suite 729: 728 pass, 1 fail (that test). Larastan 5 baseline; eslint/tsc/prettier/build ok; scope unchanged.
- [2026-09-28T06:17:15Z] [P2][impl] DONE (round 3): P2-T1/T5 marcos:reset parses manifest created_at strictly (MarcosReset::parseManifestMoment: regex ISO-8601 + DateTimeImmutable::createFromFormat round-trip; '', whitespace, 'now', 'tomorrow', garbage, numeric, date-only, wrong format, impossible date, null, missing key => clean refusal 'fecha de creación legible', exit 1, nothing touched); 13 new tests. Also fixed API item detail query count with 0 vs N neighbours (neighbour objective key via join, ItemDetails) that the new Phase 2 acceptance test caught. Full suite 742/742, larastan 5 baseline, pint/eslint/tsc/prettier/build ok. Not committed.
- [2026-09-28T06:17:44Z] [P2][test] START: round 3 re-verification (P2-T1 strict created_at, P2-T5).
- [2026-09-28T06:19:53Z] [P2][test] DONE: VEREDICTO APROBADO (round 3). P2-T1/P2-T5 fixed: strict ISO-8601 created_at; 20 hostile variants (spaces, newline, microseconds, no tz, O offset, 0000-00-00, 02-30, 24:00, +99:00, unicode/fullwidth digits, relative words, timestamps, non-strings) refused cleanly with nothing touched; valid Z/+00:00/-06:00/DST-edge accepted; offsets compared as instants. P2-T6 withdrawn (coordinator-ordered). Acceptance files untouched by implementer this round. Acceptance Phase2 feature 194/194 + browser 6/6. Full suite 767/767; larastan 5 baseline; eslint/tsc/prettier/build ok; scope unchanged.
