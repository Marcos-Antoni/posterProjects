## Purpose

Guarantees that every piece of current posterProjects data is exported to a verified backup before the
database is reset to a clean Marcos OS schema, and that the reset itself is guarded.

## ADDED Requirements

### Requirement: A Complete, Verified Export Precedes Any Reset

An artisan command MUST export all current data to a timestamped backup consisting of (a) a full native
database dump and (b) one JSON file per table (users without password hashes, projects, members, sprints,
board columns, issues, labels, issue labels, comments, habits, habit entries, habit days, QR passes,
personal access token metadata without token hashes), plus a manifest with row counts and SHA-256 checksums
of every file. The command MUST fail if any table's exported row count differs from the live count.

#### Scenario: A row-count mismatch fails the export

- **GIVEN** a table whose JSON export holds fewer rows than the live table
- **WHEN** the export verifies its manifest
- **THEN** the command MUST exit non-zero and mark the backup as unverified

### Requirement: The Backup Must Pass A Restore Rehearsal

Before a reset is allowed, the database dump MUST be restored into a scratch database and its row counts
compared to the manifest; the rehearsal result MUST be recorded next to the manifest.

#### Scenario: A failed rehearsal blocks the reset

- **GIVEN** a backup whose restore rehearsal reported a mismatch
- **WHEN** the reset command runs
- **THEN** it MUST refuse to run

### Requirement: The Clean-Slate Reset Is Guarded

The reset command MUST refuse to run unless: a verified backup with a passed rehearsal exists and is
younger than 24 hours, the owner types the confirmation phrase, and the environment is not `testing`
unless explicitly forced by the test suite. It MUST preserve the owner user record and its `mcp` and
`mobile` tokens so integrations can reconnect, and MUST leave every Marcos OS table empty.

#### Scenario: The owner and tokens survive the reset

- **GIVEN** a verified, rehearsed backup and the confirmation phrase
- **WHEN** the reset runs
- **THEN** the owner user and its `mcp` and `mobile` tokens SHALL still authenticate
- **AND** no legacy project, issue or habit row SHALL remain

### Requirement: Backups Never Enter Version Control

Backup files MUST be written outside the repository working tree (default VPS
`/root/backups/posterprojects/<timestamp>/`), and the command MUST refuse an output path inside the
repository.

#### Scenario: An output path inside the repo is refused

- **GIVEN** an output path under the application base path
- **WHEN** the export runs
- **THEN** it MUST exit non-zero without writing files
