## Purpose

Defines the Plan: the middle layer between an objective and its milestones, tasks and habits, carrying
its own 5-point plan, manual order and lifecycle.

## ADDED Requirements

### Requirement: A Plan Belongs To Exactly One Objective

A plan MUST belong to exactly one objective, MUST have a title and a complete 5-point plan to be
`active` (see `control-plan`), and MUST keep a manual position among its objective's plans. An objective
MAY hold any number of plans. A plan MUST have one lifecycle state: `draft`, `active`, `done` or `retired`.

#### Scenario: A plan is appended at the end of its objective

- **GIVEN** an objective with two plans
- **WHEN** the owner adds a third plan
- **THEN** it SHALL be placed after the existing two

### Requirement: A Plan Is Done When Its Non-Retired Items Are Done

A plan MUST become `done` automatically when every non-retired item in it is `done` and it holds at least
one item. Unchecking an item of a `done` plan MUST return it to `active`.

#### Scenario: Checking the last item completes the plan

- **GIVEN** an active plan whose only non-done, non-retired item is task A
- **WHEN** A is checked
- **THEN** the plan SHALL become `done`

### Requirement: Plans Are Never Deleted

There MUST be no delete operation for plans. Removing a plan from the active tree MUST go through the
retirement protocol, including the content decision for its items and habits (see `retirement`).

#### Scenario: No delete route exists for plans

- **GIVEN** any plan
- **WHEN** a delete request is attempted on web, API or MCP
- **THEN** no such route or tool SHALL exist
