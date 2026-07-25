# Authentication Specification

> **Reconstructed spec (2026-07-24).** Sources: `tests/Feature/AuthenticationTest.php`,
> `tests/Feature/AppearanceTest.php` (16 verified scenarios), `routes/auth.php`,
> `tests/Feature/CreateOwnerUserCommandTest.php`.

Single-owner application: there is no public registration. The owner user is created by an artisan
command.

## Requirements

### Requirement: Users Authenticate Through The Login Screen

The login page MUST render as a full page with the built Vite assets. Valid credentials MUST
authenticate the user; an invalid password MUST NOT. Authenticated users MUST be redirected away from
the login screen. Users MUST be able to log out, and guests MUST NOT.

*Verified by: `tests/Feature/AuthenticationTest.php` ("login page renders as a full page with the built
Vite assets", "users can authenticate using the login screen", "users cannot authenticate with an
invalid password", "authenticated users are redirected away from the login screen", "users can logout",
"guests cannot logout").*

#### Scenario: An invalid password does not authenticate

- GIVEN a registered user
- WHEN a login attempt uses the wrong password
- THEN the user SHALL NOT be authenticated

#### Scenario: An authenticated user is bounced off the login page

- GIVEN an authenticated user
- WHEN they request the login screen
- THEN they SHALL be redirected away

### Requirement: Login Is Rate Limited

The system MUST rate limit login after too many failed attempts.

*Verified by: `tests/Feature/AuthenticationTest.php` ("login is rate limited after too many failed
attempts").*

#### Scenario: Repeated failures are throttled

- GIVEN several consecutive failed login attempts for the same account
- WHEN the threshold is exceeded
- THEN further attempts SHALL be rate limited

### Requirement: The Owner User Is Created By Command

The system MUST provide an artisan command to create the owner user. There MUST be no public
registration route.

*Verified by: `tests/Feature/CreateOwnerUserCommandTest.php`.*

#### Scenario: The owner is provisioned from the CLI

- GIVEN a fresh installation
- WHEN the create-owner command runs
- THEN the owner user SHALL exist and be able to authenticate

### Requirement: Appearance Preference Persists

The system MUST let an authenticated user set an appearance (theme) preference and MUST persist it
across requests.

*Verified by: `tests/Feature/AppearanceTest.php` (8 scenarios).*

#### Scenario: The chosen appearance survives a reload

- GIVEN an authenticated user selects an appearance
- WHEN they reload the application
- THEN the selected appearance SHALL still apply
