## REMOVED Requirements

### Requirement: Streaks Are Recurrence-Aware
**Reason**: A single miss (or a partial day) breaking the streak to zero is the "reset at first failure" anti-pattern Marcos OS exists to avoid (R11, R12).
**Migration**: Replaced by `Streaks Are Tolerant: Never Miss Twice` (ADDED below), which keeps recurrence awareness, the pending-today rule and the best-streak memory.

### Requirement: Archiving Is Reversible And Blocks New Entries
**Reason**: Bare archiving is replaced by the retirement protocol, which requires a written reason (R13).
**Migration**: Replaced by `Retiring A Habit Follows The Retirement Protocol` (ADDED below). The no-destroy rule and the entry block are preserved.

## ADDED Requirements

### Requirement: Habits May Hang From An Objective Or Plan And Survive Its Closure

A habit MAY be linked to one objective and optionally one plan of that objective. The link MUST be
informational: closing or retiring the objective MUST NOT archive, retire or modify the habit by itself.
Instead, closing an objective MUST present the continuation decision defined in `reviews`; habits the
owner keeps MUST remain active and become unlinked-or-relinked as chosen; habits the owner does not keep
MUST go through the retirement protocol.

#### Scenario: A habit survives its objective's closure

- **GIVEN** an active habit linked to objective `SALUD`
- **WHEN** the owner closes `SALUD` and chooses to keep the habit
- **THEN** the habit SHALL remain active, keep its history and streak
- **AND** its objective link SHALL be cleared or moved to the objective the owner chose

#### Scenario: Retiring an objective does not silently retire its habits

- **GIVEN** an active habit linked to an objective
- **WHEN** the objective is retired
- **THEN** the habit SHALL NOT be retired unless the owner explicitly decides so in the same flow

### Requirement: Every Habit Has A 2-Minute Version

Creating or updating a habit MUST require a **2-minute version** (the smallest physical start of the
habit). The today view and the Now screen MUST show it. Logging the 2-minute version MUST count as a
recorded day for the streak and as an identity vote, even when a quantitative target is not reached; it
MUST NOT mark a quantitative day `completed` unless the target is reached.

#### Scenario: The 2-minute version keeps the streak alive

- **GIVEN** a daily quantitative habit with target 30 and a 2-minute version
- **WHEN** the owner logs only the 2-minute version today
- **THEN** today SHALL count as shown-up for the tolerant streak and identity votes
- **AND** the day SHALL NOT be `completed` for the quantitative target

### Requirement: Streaks Are Tolerant: Never Miss Twice

The system MUST compute the current and best streak according to the habit's recurrence mode with a
tolerance of one miss: the current streak MUST break only after **two consecutive missed scheduled
opportunities** ("never miss twice"). A single miss MUST be reported as `at_risk` (a neutral "hoy toca
volver" state), not as a break. A still-pending today MUST NOT count as a miss. The best streak MUST
remember the longest historical run. A habit with no history MUST report zero.

- **Daily**: an opportunity is a calendar UTC-6 day.
- **Specific weekdays**: an opportunity is a scheduled day; unscheduled days never count.
- **Times per week**: an opportunity is a closed week; the streak breaks only after two consecutive
  weeks closed under quota; the in-progress week never counts as a miss.

A day counts as shown-up when it is `completed` or when the 2-minute version was logged.

#### Scenario: A single miss does not break the streak

- **GIVEN** a daily habit shown up on 10 consecutive days, then missed yesterday
- **WHEN** the streak is computed today before any entry
- **THEN** the current streak SHALL still be 10
- **AND** the streak state SHALL be `at_risk`

#### Scenario: Two consecutive misses break the streak

- **GIVEN** a daily habit shown up on 10 consecutive days, then missed two consecutive days
- **WHEN** the streak is computed
- **THEN** the current streak SHALL be 0
- **AND** the best streak SHALL remain at least 10

#### Scenario: A miss followed by a show-up keeps the run

- **GIVEN** a daily habit that missed one day between two shown-up days
- **WHEN** the streak is computed
- **THEN** the run SHALL continue across the missed day without counting the missed day itself

#### Scenario: A pending today does not count as a miss

- **GIVEN** a habit whose today is scheduled but not yet logged
- **WHEN** the streak is computed
- **THEN** today SHALL NOT be counted as a miss

#### Scenario: A times-per-week streak needs two under-quota weeks to break

- **GIVEN** a times-per-week habit that closed one week under quota
- **WHEN** the next week is still in progress
- **THEN** the streak SHALL NOT break
- **AND** it SHALL break only if that next week also closes under quota

### Requirement: Identity Votes Are A Proportion, Not A Score

A habit MAY carry an identity statement; when it does not, it inherits its objective's identity
statement. Each scheduled opportunity MUST be a potential vote; each shown-up opportunity MUST be one vote
cast. The system MUST expose, per identity statement, the proportion of votes cast over scheduled
opportunities for the rolling last 7 and last 30 UTC-6 days. It MUST be shown as a proportion ("14 de 20
votos"), MUST NOT be combined into any composite score, ranking or level.

#### Scenario: Votes are counted per identity

- **GIVEN** two habits sharing the identity "soy alguien que se mueve" with 10 scheduled opportunities in
  the last 7 days and 7 shown-up
- **WHEN** identity votes are computed
- **THEN** that identity SHALL report 7 votes out of 10 for the last 7 days

### Requirement: A Missed Day Restarts With A 2-Minute Entry

After one or more missed opportunities, the next time the habit is shown, the system MUST offer a
"volver" (restart) action that logs the 2-minute version. The system MUST NOT show debt (missed-count
backlogs), MUST NOT ask to compensate missed days, MUST NOT use the danger color for misses, and MUST NOT
send any reminder or notification about a miss.

#### Scenario: A missed habit offers a restart, not a debt

- **GIVEN** a habit missed yesterday
- **WHEN** the owner opens the today view
- **THEN** the habit SHALL show a restart action with its 2-minute version
- **AND** no missed-day count or catch-up request SHALL be shown

### Requirement: Habits Scale Progressively

A habit MAY define an ordered ladder of levels (for example 2 min → 10 min → 20 min), each with its own
target and 2-minute version. The system MUST suggest moving up a level only after the current level was
shown up on at least 80% of opportunities over the last 14 UTC-6 days, and MUST suggest stepping down
after the tolerant streak breaks. Level changes MUST be applied only by the owner (or by AI through
`ai-operations` as a major change) and MUST NOT reset the streak.

#### Scenario: A level-up is suggested at the edge of capability

- **GIVEN** a habit shown up on 12 of 14 opportunities at its current level
- **WHEN** the habit screen is rendered
- **THEN** a level-up suggestion SHALL be shown
- **AND** the level SHALL NOT change until the owner accepts

### Requirement: Retiring A Habit Follows The Retirement Protocol

A habit MUST be retired only through the retirement protocol (see `retirement`), with a written reason.
A retired habit MUST reject new entries, MUST be absent from the today view, and MUST keep its full
history. Restoring it MUST re-enable entries without altering history. There MUST be no destroy route:
habits are never deleted.

#### Scenario: A retired habit rejects entries

- **GIVEN** a retired habit
- **WHEN** the owner tries to log an entry through web, API or MCP
- **THEN** the request MUST be rejected

#### Scenario: Habits cannot be destroyed

- **GIVEN** any habit
- **WHEN** a destroy route is attempted
- **THEN** no such route SHALL exist
