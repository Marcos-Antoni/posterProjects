## Purpose

Defines the Now screen and its execution rules: one active task at a time, a 2-minute start, a silent
check-in cue, an "I'm stuck" escape, celebration scaled by size, and no punishment or engagement mechanics.

## ADDED Requirements

### Requirement: Exactly One Active Task At A Time

At most one item MUST be `active` for the owner at any time, across all objectives. Starting an item MUST
make any previously active item `available` again (or `locked` if its prerequisites changed). A locked,
done or retired item MUST NOT be started.

#### Scenario: Starting a second task pauses the first

- **GIVEN** task A is active
- **WHEN** the owner starts task B
- **THEN** B SHALL be `active`
- **AND** A SHALL be `available`

### Requirement: The Now Screen Shows One Task, Starting With Its 2-Minute Version

The Now screen MUST show only: the active task (or, if none, ONE suggested available task — the first
available item of the weekly main priority, else the oldest available item of the first active objective),
its 2-minute version as the primary call to action, what completing it unlocks, and the current habit
checks for today in a compact row. It MUST NOT show lists of other tasks, feeds, counters of pending work or
infinite scroll.

#### Scenario: With no active task, one suggestion is shown

- **GIVEN** no active task and several available items
- **WHEN** the Now screen renders
- **THEN** exactly one suggested task SHALL be shown with its 2-minute version
- **AND** no list of other available tasks SHALL be shown

#### Scenario: With nothing available, the screen invites capture or planning

- **GIVEN** no available items at all
- **WHEN** the Now screen renders
- **THEN** it SHALL offer to capture an idea or open an objective, without error or empty-state blame

### Requirement: A Silent Check-In Cue Every 25 Minutes

While a task is active and the Now screen is open, the system MUST show a silent, non-interrupting
check-in cue every 25 minutes of focus time: no sound, no modal, no focus stealing, no browser
notification, no blocking of interaction. The cue MUST be discreet: a 3px line that fills over the
25-minute tramo plus a small dot and inline text, inside the Now card. The cue MUST offer exactly three
options: "Sigo" (dismisses the cue until the next 25-minute tramo), "Terminé" (the same action as
"Marcar hecho") and "Estoy trabado" (the same flow as the "estoy trabado" button). The cue MUST NOT be
announced to screen readers (no live region; `aria-live="off"`), but its options MUST be labeled and
reachable by keyboard in normal tab order. The cue MUST disappear on its own if ignored. Focus time MUST be
measured from the task's start and persisted, so a reload does not reset it.

#### Scenario: The cue never interrupts

- **GIVEN** a task active for 25 minutes with the Now screen open
- **WHEN** the cue appears
- **THEN** no sound SHALL play, no modal SHALL open, keyboard focus SHALL NOT move and no screen-reader
  announcement SHALL be made

#### Scenario: The cue options are reachable by keyboard

- **GIVEN** the cue is visible
- **WHEN** the owner tabs through the Now card
- **THEN** "Sigo", "Terminé" and "Estoy trabado" SHALL each receive focus and expose an accessible label

#### Scenario: Each cue option maps to an existing action

- **GIVEN** the cue is visible
- **WHEN** the owner chooses "Terminé"
- **THEN** the task SHALL be completed exactly as with "Marcar hecho"
- **WHEN** the owner chooses "Estoy trabado"
- **THEN** the same stuck flow as the "estoy trabado" button SHALL start
- **WHEN** the owner chooses "Sigo"
- **THEN** the cue SHALL disappear and SHALL NOT reappear until the next 25-minute tramo

#### Scenario: A reload keeps the focus clock

- **GIVEN** a task active for 20 minutes
- **WHEN** the owner reloads the page
- **THEN** the next cue SHALL appear after 5 more minutes, not 25

### Requirement: "I'm Stuck" Returns One Smaller Physical Action

The Now screen MUST offer an "estoy trabado" action. It MUST return exactly one smaller physical action
for the current task. When an AI route is available (MCP proposal via `shrink-step`, or the bridge), the
action MUST be produced by the AI as a minor operation; otherwise the system MUST fall back to a fixed
prompt that asks the owner to write the smallest next physical action. The accepted smaller action MUST
become the task's new 2-minute version, keeping the previous one in history.

#### Scenario: Stuck without AI still returns one action

- **GIVEN** the AI bridge is disabled
- **WHEN** the owner presses "estoy trabado"
- **THEN** the system SHALL ask for one smaller physical action and save it as the new 2-minute version

### Requirement: Celebration Scales With Size

Completing a task MUST trigger the unlock animation (see `unlock-graph`). Completing a milestone MUST open
the "summit" moment that records evidence (see `reviews`). Completing an objective MUST lead to the
learning review (see `reviews`). Celebrations MUST NOT use points, badges, leaderboards or sounds.

#### Scenario: A milestone opens the summit moment

- **GIVEN** an available milestone
- **WHEN** the owner completes it from the Now screen
- **THEN** the summit moment SHALL open and ask for evidence

### Requirement: No Punishment, No Debt, No Engagement Mechanics

The product MUST NOT render any item, habit, metric or date in the danger color or with overdue wording;
MUST NOT accumulate debt (missed counts, catch-up lists); MUST NOT send engagement notifications (email,
push or browser) of any kind; MUST NOT show feeds, infinite scroll, story points, velocity, or composite
productivity scores. A day without activity MUST be followed by a restart offer with a 2-minute entry.

#### Scenario: Returning after inactivity offers a restart

- **GIVEN** no item was checked and no habit was logged for three days
- **WHEN** the owner opens the Now screen
- **THEN** it SHALL offer a restart with one 2-minute action
- **AND** it SHALL NOT show how many days were missed
