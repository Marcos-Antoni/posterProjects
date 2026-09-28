## Purpose

Reviews close the loop at three sizes: the weekly priority review, the milestone "summit" with recorded
evidence, and the objective learning review with the habit continuation decision.

## ADDED Requirements

### Requirement: The Weekly Priority Is One Main Priority Plus At Most Two Maintenance Standards

For each ISO week (UTC-6), the owner MUST be able to set exactly one main priority (an objective or a
plan) and at most two maintenance standards (objectives or habits kept at a minimum level). A third
maintenance standard MUST be rejected. The main priority MUST drive the Now screen suggestion
(see `now-focus`).

#### Scenario: A third maintenance standard is rejected

- **GIVEN** a week with one main priority and two maintenance standards
- **WHEN** the owner adds a third maintenance standard
- **THEN** the request MUST be rejected with a Spanish message

### Requirement: The Weekly Review Shows Progress Before Asking

The weekly review screen MUST first show the past week's progress (items done, milestones reached, habit
shown-up days, tolerant streaks, identity votes) and only then ask: what worked, what got in the way, and
the next week's priority. It MUST NOT show missed counts as debt. Skipping a weekly review MUST NOT
block any feature.

#### Scenario: Progress comes before questions

- **GIVEN** the owner opens the weekly review
- **WHEN** the screen renders
- **THEN** the progress section SHALL appear above the questions

### Requirement: A Milestone Summit Records Evidence

Completing a milestone MUST record evidence: a required text and an optional link or uploaded image
(max 5 MB, images only). The summit moment MUST show what the milestone unlocked and the path of items
completed to reach it.

#### Scenario: Evidence is stored with the milestone

- **GIVEN** the owner completes a milestone with text evidence and an image
- **WHEN** the summit is saved
- **THEN** the milestone SHALL be `done` with the text and image retrievable from its detail

### Requirement: Closing An Objective Requires A Learning Review And A Habit Decision

Closing an objective MUST require a learning review with three answers (what I learned, what I would
repeat, what I would change) and, for every active habit linked to the objective, an explicit decision:
keep (optionally relinking to another active objective) or retire (through the retirement protocol).
The objective MUST become `closed` only when the review and every habit decision are saved together.

#### Scenario: Closing asks about each linked habit

- **GIVEN** an objective with two linked active habits
- **WHEN** the owner closes it
- **THEN** the flow SHALL ask keep-or-retire for each habit
- **AND** the objective SHALL NOT be closed until both decisions are made

### Requirement: Reviews Are Kept As History

Weekly reviews, summits and learning reviews MUST be listed chronologically on a reviews history screen
and MUST NOT be deletable.

#### Scenario: Past reviews are listed newest first

- **GIVEN** three weekly reviews and one learning review
- **WHEN** the reviews history renders
- **THEN** all four SHALL be listed, newest first
