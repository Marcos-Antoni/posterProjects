# Archive Report — api-qr-login

**Change**: api-qr-login
**Date**: 2026-08-01
**Mode**: openspec
**Verdict**: Specs merged, folder move NOT performed (no shell/move capability available to this executor)

## Gate Status

- Verify report: `openspec/changes/api-qr-login/verify-report.md` — **PASS**. 12/12 properties CONFIRMED. 0 CRITICAL, 0 WARNING, 1 non-blocking SUGGESTION (migration rollback verified by structural read, not an executed `migrate:fresh`/`migrate:rollback` cycle — low risk, documented, does not block archive).
- Task Completion Gate: `openspec/changes/api-qr-login/tasks.md` — 47/47 checklist lines `[x]`, zero `[ ]`. Confirmed consistent with `apply-progress.md`.
- Test evidence: `php artisan test --compact` → 506/506 passed (re-run independently during verify), matching this session's stated 539/539 full-suite baseline after both changes.
- No CRITICAL issues found. Archive proceeds.

## Specs Synced

| Domain | Action | Details |
|--------|--------|---------|
| `api-auth` | Updated (MODIFIED) | 2 requirements replaced in place: **Single Active Mobile Token** (generalized from `POST /api/v1/login`-only to any mobile-session issuance, credentials or QR redemption — 1 new scenario added), **Versioned OpenAPI Contract Document** (bearer-auth exemption promoted from a hard-coded path list to a stated contract property — 1 new scenario added). All other `api-auth` requirements (Credentials Exchange, Authenticated Identity Exposure, Token Revocation, Ability Boundary, Brute-Force Protection, JSON Error Contract, Web Revocation) left untouched. |
| `api-qr-login` | Created (new domain, no prior main spec) | Full spec copied from the change's delta: 8 requirements (Pass Minting, Pass Entropy/TTL/One-Live-Pass, Redemption Indistinguishable From Credentials, Atomic Single-Use Consumption, Uniform Redemption Failure, Redemption Rate Limiting, Web Feedback On Consumption, Owner Acknowledgement Mints Past A Consumed Pass). Two facts folded in as explicit reasoning during merge (present in tasks/verify-report but not verbatim in the delta spec text): (1) the pass hash is plain unsalted `sha256`, never a salted hash, *because* a salted hash cannot be looked up by equality and would force the read-then-write shape the atomic-consumption requirement forbids; (2) the atomic-UPDATE requirement now states explicitly why a read-then-check-then-write shape reopens the two-phones race. The owner-acknowledgement requirement already stated the "theft-detection signal must not be destroyed silently" reasoning in the delta; carried through unchanged. |

No REMOVED or RENAMED requirements in this change — no destructive merge, no `config.yaml` archive warning triggered.

## Archive Contents (present in the pre-move change folder)

- `proposal.md` ✅
- `specs/api-auth/spec.md` ✅ (delta)
- `specs/api-qr-login/spec.md` ✅ (delta)
- `design.md` ✅
- `tasks.md` ✅ (47/47 tasks complete)
- `apply-progress.md` ✅
- `verify-report.md` ✅ (PASS)

## Source of Truth Updated

- `openspec/specs/api-auth/spec.md` — 2 requirements replaced in place.
- `openspec/specs/api-qr-login/spec.md` — created.

## Folder Move — NOT Performed

This executor has no shell, move, or delete capability (Read/Write/Edit/Glob/mem tools only). Per
the archive skill's explicit instruction, no duplicate copy was written to
`openspec/changes/archive/2026-08-01-api-qr-login/` to avoid an un-deletable duplicate.

**Required follow-up (orchestrator or a shell-capable agent):**
```
mv openspec/changes/api-qr-login openspec/changes/archive/2026-08-01-api-qr-login
```

Until this move runs, `openspec/changes/api-qr-login/` (including this report) remains in the active
changes directory. The merge into `openspec/specs/` above is already complete and correct regardless
of when the move happens.

## SDD Cycle Status

Planned, implemented, and verified. Spec sync complete. Folder archival pending a shell-capable
executor.
