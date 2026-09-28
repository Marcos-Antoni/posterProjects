## MODIFIED Requirements

### Requirement: Users Authenticate Through The Login Screen

The login page MUST render as a full page with the built Vite assets. Valid credentials MUST
authenticate the user; an invalid password MUST NOT. After a successful login, and whenever an
authenticated user requests `/` or the login screen, the user MUST be redirected to the **Now** screen
(see `now-focus`) — the single entry point of Marcos OS. Users MUST be able to log out, and guests MUST
NOT.

#### Scenario: An invalid password does not authenticate

- **GIVEN** a registered user
- **WHEN** a login attempt uses the wrong password
- **THEN** the user SHALL NOT be authenticated

#### Scenario: An authenticated user is bounced off the login page

- **GIVEN** an authenticated user
- **WHEN** they request the login screen
- **THEN** they SHALL be redirected to the Now screen

#### Scenario: A successful login lands on Now

- **GIVEN** the owner submits valid credentials
- **WHEN** the login succeeds
- **THEN** the owner SHALL be redirected to the Now screen

## ADDED Requirements

### Requirement: Remote AI Execution Requires Recent Password Confirmation

Any web action that triggers execution on the VPS through the AI bridge (see `ai-cli-bridge`) or that
creates an AI permission grant (see `ai-operations`) MUST require that the owner confirmed their password
within the last 15 minutes in the current session. Without a recent confirmation the action MUST redirect
to the password confirmation screen and MUST NOT execute.

#### Scenario: A stale session must reconfirm before running the bridge

- **GIVEN** an owner session whose last password confirmation was 30 minutes ago
- **WHEN** the owner submits a bridge request
- **THEN** the request SHALL be redirected to password confirmation
- **AND** no bridge run SHALL be queued

### Requirement: The Owner's Appearance Preference Persists

The settings area MUST offer an appearance page with exactly one control: a theme selector with the
options "claro", "oscuro" and "sistema". The choice MUST be stored per user and MUST default to "sistema"
(follow the operating system's color scheme). Every page MUST render with the stored theme. The web UI
MUST NOT offer profile or password editing.

#### Scenario: A new user follows the system theme

- **GIVEN** a user who never chose a theme
- **WHEN** any page renders
- **THEN** the theme SHALL follow the operating system's color scheme ("sistema")

#### Scenario: The chosen theme survives a new session

- **GIVEN** the owner selects "oscuro" on the appearance page
- **WHEN** the owner logs out and logs in again from another browser
- **THEN** every page SHALL render in the dark theme and the selector SHALL show "oscuro"

### Requirement: The Password Is Changed Only From The CLI

Changing the owner's password MUST be done with an artisan command run on the server; there MUST NOT be a
web route that changes the password.

#### Scenario: The artisan command changes the password

- **GIVEN** the owner runs the password command with a new password entered twice
- **WHEN** both entries match and pass validation
- **THEN** the new password SHALL authenticate at the login screen and the old one SHALL NOT
