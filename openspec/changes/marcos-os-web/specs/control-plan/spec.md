## Purpose

Gives every objective and plan the Control 5-point plan and a control map, so each goal states its
outcome, deadline, single metric, risks and contingency, and separates what depends on Marco from what does not.

## ADDED Requirements

### Requirement: Every Objective And Plan Carries A 5-Point Plan

Every objective and every plan MUST carry the five points: **outcome** (what is true when it is done),
**deadline** (a UTC-6 date), **ONE metric** (a single name with a numeric target and an optional current
value), **what can go wrong** (at least one risk), and **contingency** (what Marco does if a risk
materializes). An objective or plan MUST NOT become `active` while any of the five is missing. Exactly
one metric MUST exist per objective or plan; a second metric MUST be rejected.

#### Scenario: A plan with two metrics is rejected

- **GIVEN** a plan form with two metrics
- **WHEN** it is submitted
- **THEN** it MUST be rejected with a Spanish message explaining that only one metric is allowed

#### Scenario: A complete 5-point plan allows activation

- **GIVEN** an objective with outcome, deadline, one metric, one risk and one contingency
- **WHEN** the owner activates it
- **THEN** the objective SHALL become `active`

### Requirement: The Control Map Separates Three Zones

Every objective and plan MUST allow a control map with three zones: **depends on me**, **I can
influence**, **does not depend on me**. Each zone holds zero or more short entries. Items (tasks and
milestones) MUST only be created for the first two zones; an entry in "does not depend on me" MUST NOT be
convertible into a task.

#### Scenario: An outside-control entry cannot become a task

- **GIVEN** a control-map entry in the zone "does not depend on me"
- **WHEN** the owner tries to convert it into a task
- **THEN** the action MUST NOT be offered

### Requirement: The Metric Current Value Is Updated Without Punishment

The owner (or AI as a minor action when Marco tells it the value) MUST be able to update the metric's
current value. The system MUST show the metric as progress toward the target and MUST NOT color it as
failure when behind schedule.

#### Scenario: A behind-schedule metric is shown neutrally

- **GIVEN** a metric at 20% with 80% of the time to deadline elapsed
- **WHEN** the objective screen is rendered
- **THEN** the metric SHALL NOT use the danger color token

### Requirement: Plans And Habits Scale At The Edge Of Capability

A plan MAY be marked as a progression level of its objective (level 1, 2, 3 ...). The system MUST suggest
creating or activating the next level only when the current level's items are at least 80% done, and MUST
never activate a next level automatically.

#### Scenario: The next plan level is suggested, not activated

- **GIVEN** a level-1 plan with 8 of 10 items done
- **WHEN** the objective screen is rendered
- **THEN** a suggestion to prepare level 2 SHALL be shown
- **AND** no level-2 plan SHALL be activated without the owner's action
