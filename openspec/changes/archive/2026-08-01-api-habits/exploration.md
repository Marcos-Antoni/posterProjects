# Exploration — `api-habits`

**Phase:** sdd-explore · **Status:** done · **Next:** sdd-clarify (interactive), then sdd-propose

## Load-bearing fact: how the habit day is anchored

`app/Models/Habit.php:105-149` (`recordEntry()`) is the single place a day gets assigned:

```php
$localTime = $entry->logged_at->clone()->setTimezone(Config::string('habits.timezone'));
$entryDate = $localTime->toDateString();
```

- `logged_at` is stamped `now()` in UTC (`Habit.php:110`).
- `Config::string('habits.timezone')` resolves to `Etc/GMT+6` (`config/habits.php:19`), which is UTC-6 — the POSIX sign is inverted, and the file says so. Its docblock (`config/habits.php:14-16`) states this is "NOT the application timezone and must never replace `app.timezone`".
- `Habit::todayLocalDate()` (`Habit.php:304-307`) is the read-side equivalent, used by `HabitController::today()/show()`, the `TodayHabits` and `ShowHabit` MCP tools, and every streak/completion computation.

Boundary behavior is already pinned by `tests/Feature/HabitEntryLoggingTest.php:153-183`: an entry at 23:30 UTC-6 lands on the UTC-6 day, not the UTC one; two entries on opposite UTC sides of the same UTC-6 day fold into one `habit_days` row. The day is claimed with `INSERT ... ON CONFLICT DO NOTHING` and re-read under `lockForUpdate()` (`Habit.php:116-127`), so concurrent entries serialize.

**The separation from sprint state is real and deliberate.** `openspec/specs/api-board-sprints/spec.md:47-55` says sprint `state` anchors to `today()` in the app timezone (UTC) and that the UTC-6 rule "is habits-only and MUST NOT be reused here, or `state` would silently disagree with the web board for six hours a day."

**Implication:** any endpoint computing "today" for habits MUST call `Habit::todayLocalDate()`. Reusing `Habit::recordEntry()` rather than reimplementing the rollup is the only way to guarantee this by construction.

## Current state

### Models

- **`Habit`** — the definition: `habit_type` (`YesNo | Quantitative`), `unit`, `daily_target`, `recurrence_type` (`Daily | SpecificWeekdays | TimesPerWeek`), `weekdays` (ISO 1-7), `times_per_week`, `planned_time`, `archived_at`. Business logic lives here: `recordEntry()`, `isScheduledOn()`, `currentStreak()`, `bestStreak()`, `completionForPeriod()` — all computed on read, never cached.
- **`HabitEntry`** — append-only log: `habit_id`, `amount`, `logged_at` (UTC).
- **`HabitDay`** — the persisted daily aggregate, one row per `(habit_id, entry_date)` with a unique constraint (`database/migrations/2026_07_23_022328_create_habit_days_table.php:25`): `accumulated_amount`, `completion_percent` (may exceed 100, never clamped), `completed`, `planned_delta_minutes`. Streaks and completion read from this rollup, never from raw entries.

Field applicability is enforced by `exclude_unless` rules in `StoreHabitRequest.php:35-40` and `UpdateHabitRequest.php:39-44`; `HabitController.php:176-182` resets the three conditional fields to `null` before applying an update, so switching type or recurrence never leaves stale values.

### Web surface (`routes/web.php:64-74`)

| Route | Behavior |
| --- | --- |
| `GET habits` | Active habits scheduled for the current UTC-6 day, with `todayProgress()` |
| `GET habits/manage` | Every habit, active and archived |
| `GET habits/{habit}` | Streaks, period completion, daily series for the chart |
| `POST habits` / `PATCH habits/{habit}` | Create and edit |
| `POST habits/{habit}/archive` / `unarchive` | Reversible; there is NO destroy route (`HabitPolicy.php:8-11`) |
| `POST habits/{habit}/entries` | The write. `StoreHabitEntryRequest` requires `amount` only for `Quantitative`, rejects archived habits (`:48-63`) |

**Double-logging always ADDS** (`Habit.php:133`). There is no dedup, no idempotency key, no replace mode anywhere. Logging a yes/no habit twice in one day yields `accumulated_amount=2`, `completion_percent=200`, still `completed=true`.

### Recurrence — what "due today" means

`Habit::isScheduledOn()` (`Habit.php:157-164`): `Daily` and `TimesPerWeek` are always scheduled; `SpecificWeekdays` only when `dayOfWeekIso` is in `weekdays`. So a `TimesPerWeek` habit appears every day, and completion is judged by `week_recorded_days` against `times_per_week`, not by a daily flag.

### Quantitative vs binary

Target is `max(1, daily_target)` for `Quantitative`, fixed `1` for `YesNo`. Accumulation is additive from the locked row. `completion_percent` is not clamped. `completed` is `accumulated >= target`.

**The numeric-cast scar.** `HabitEntryController.php:20-23` and `LogHabitEntry.php:51-53` carry the same comment: the `integer` validation rule accepts numeric strings without casting, and real form submissions send strings, so a naive `is_int($amount)` check silently collapsed every typed amount to `1`. The fix casts with `is_numeric($amount) ? (int) $amount : 1`. `tests/Browser/HabitFlowTest.php:64-102` pins it, and `openspec/config.yaml:11` records that the browser suite is what caught it.

### MCP tools — a working reference implementation

`app/Mcp/Tools/Habits/` already exposes the full surface: `ListHabits`, `TodayHabits`, `ShowHabit`, `CreateHabit`, `UpdateHabit`, `ArchiveHabit`, `UnarchiveHabit`, `LogHabitEntry`. They replay the existing `FormRequest` classes via `ReplaysFormRequest`, so validation and authorization stay identical to the web by construction. All resolve via `$user->habits()->whereKey(...)` and return not-found on absence.

This is effectively "habits API v1" already working over HTTP.

### Existing spec

`openspec/specs/habits/spec.md` — 237 lines, the largest in the project, reconstructed from 71 verified test scenarios. It codifies UTC-6 anchoring, accumulation with an unclamped percent, planned-vs-actual delta, recurrence-aware streaks, period completion, reversible archiving with no destroy, strict per-user scoping (404 not 403), and the today view. **The API must not contradict it.**

### Existing tests

`HabitTest`, `HabitControllerTest`, `HabitTodayViewTest`, `HabitMetricsTest` (18 metric scenarios), `HabitEntryLoggingTest` (15 scenarios including both UTC-6 boundary cases and the locked-row concurrency), `McpHabitToolsTest`, and `tests/Browser/HabitFlowTest.php`. Nothing under `/api/v1` — that surface does not exist.

## House style to inherit

- Auth: `auth:sanctum` + `abilities:mobile` (`routes/api.php:24`).
- Resource shape pinned by exact field lists, documented as spec requirements.
- Envelope: `{"data": ...}` for single and unpaginated; full paginator envelope for paginated.
- 404-not-403 non-disclosure for cross-user access.
- JSON error contract: 401 with `WWW-Authenticate: Bearer`, 403, 404, 422, 429 — rendered via typed handlers in `bootstrap/app.php:56-82`.
- `openapi/v1.json` enforced bidirectionally by `tests/Feature/ApiContractTest.php`.
- **Every shipped endpoint is GET-only.** Entry-logging would be the first `/api/v1` write.

## Approaches

### 1. Thin wrapper over existing model and request logic (recommended)

New `Api\V1\HabitController` and `HabitEntryController` that resolve the habit scoped to `$request->user()`, reuse the existing `FormRequest` classes, and call `Habit::recordEntry()` / `Habit::todayLocalDate()` unchanged.

- **Pros:** cannot regress the day anchoring or the numeric cast, because it does not reimplement either. Matches how MCP already solved this.
- **Cons:** `StoreHabitEntryRequest`'s lenient `integer` rule was designed for form input; needs a decision for JSON.
- **Effort:** Low-Medium.

### 2. Bespoke API-native write path

Dedicated request classes and possibly a service layer.

- **Pros:** freedom to shape mobile-specific validation.
- **Cons:** duplicates the UTC-6 logic and the numeric-cast guard in a second place — exactly the failure mode to avoid.
- **Effort:** Medium-High. Rejected.

## Risks

| Severity | Risk |
| --- | --- |
| HIGH | **Day-anchoring regression.** Any hand-rolled "today" using `now()`/`today()` mis-dates entries by up to six hours. Mis-dated entries need a data migration to fix, not a code change. |
| HIGH | **Numeric-cast regression.** A client bug or a proxy re-encoding could send `amount` as a string. The `is_numeric()`-then-cast guard must be preserved. |
| MEDIUM | **No idempotency exists on any surface.** Shipping entry-logging with the same additive behavior means a fat-finger double tap double-counts. Owner decision required. |
| LOW | `ApiContractTest` requires `openapi/v1.json` updated in the same change as any new route. |

## Open questions for the owner — RESOLVED by `sdd-clarify` 2026-08-01

1. **Write scope** → logging and un-logging only. No create/edit/archive from the phone.
2. **Double-logging** → stays additive, and a decrement button is added to correct mistakes.
3. **Which habits the phone shows** → active and scheduled for the UTC-6 today, same as the web.
4. **Offline** → explicitly deferred. Not in this change.
5. **`amount` validation** → the phone never sends an amount; a tap is always +1.
6. **Identifiers** → numeric `id`.

See the clarified requirements below.

---

# Requisitos aclarados (clarify)

## 1. Historia de Usuario Enriquecida

Como dueño de Poster, quiero ver en el teléfono los hábitos que me tocan hoy y marcarlos con un solo toque, para registrarlos en el momento y el lugar donde efectivamente los hago, sin tener que abrir la computadora.

Todos los hábitos se marcan igual: **un toque suma 1**. Los de sí/no quedan cumplidos con ese toque; los cuantitativos avanzan de a una unidad hacia su objetivo, así que registrar 3 vasos de agua son 3 toques. La app nunca pide escribir una cantidad.

Como un toque de más es fácil en un teléfono, cada hábito tiene además un botón para **restar 1**. El descuento afecta el acumulado del día, pero **un día que ya llegó a su objetivo no vuelve a estar incumplido**: las rachas nunca se rompen hacia atrás por haber corregido un error de dedo.

## 2. Objetivos y Alcance

### 2.1 Qué Incluye

- Listar los hábitos activos programados para el día actual, anclado a UTC-6.
- Mostrar por cada hábito su progreso del día: acumulado contra objetivo, y si ya está cumplido.
- Para los hábitos de "X veces por semana", mostrar cuántos días ya se registraron esa semana.
- Sumar 1 al hábito con un toque.
- Restar 1 al hábito, sin descumplir un día ya cumplido y sin bajar de cero.

### 2.2 Qué NO Incluye

- **Crear, editar o borrar hábitos** desde el teléfono. Eso sigue siendo de la web.
- **Archivar o desarchivar** desde el teléfono.
- **Ingresar una cantidad a mano.** No hay campo numérico: todo es de a un toque.
- **Marcar sin conexión.** La app requiere internet; no hay cola local ni sincronización posterior.
- **Editar días pasados.** Solo se opera sobre el día actual.
- **Ver rachas, gráficos o el historial.** El detalle del hábito sigue siendo de la web.
- **Borrar entradas del registro.** Restar ajusta el acumulado del día; el registro de entradas queda intacto.

## 3. Arquitectura del Flujo

### 3.1 Actores

- **Dueño** — la única persona con hábitos; opera desde el teléfono.
- **App móvil** — muestra la lista y envía los toques.
- **API** — resuelve el día en UTC-6, acumula y devuelve el progreso.

### 3.2 Precondiciones

- El dueño tiene una sesión abierta en la app, con un token de ability `mobile`.
- Hay al menos un hábito activo y programado para hoy; si no, se muestra el estado vacío.
- Hay conexión a internet.

### 3.3 Disparador

- El dueño abre la pantalla de hábitos, o toca el botón de sumar o restar de un hábito.

## 4. Ciclos de Comportamiento

### 4.1 Flujo Principal

1. El dueño abre la pantalla de hábitos.
2. La app pide los hábitos de hoy y los muestra con su progreso.
3. El dueño toca "sumar" en un hábito.
4. La API acumula 1 sobre el día en curso, anclado a UTC-6, y recalcula el porcentaje y el estado de cumplido.
5. La app actualiza ese hábito con el progreso que devolvió la API.
6. Si el hábito llegó a su objetivo, se muestra como cumplido.

### 4.2 Flujos Alternativos y Errores

- **Toque de más:** el dueño toca "restar". El acumulado del día baja 1. Si el día ya estaba cumplido, **sigue cumplido**.
- **Restar en cero:** el botón de restar está deshabilitado cuando el acumulado del día es 0. La API igual rechaza el intento, para que la regla no dependa de la interfaz.
- **Hábito archivado:** la API lo rechaza. No debería aparecer en la lista, pero la regla se valida igual en el servidor.
- **Hábito de otra persona o inexistente:** responde 404, indistinguible entre ambos casos, igual que el resto de la API.
- **Sin conexión:** la app avisa que no pudo registrar e invita a reintentar. No encola nada.
- **Sesión vencida:** un 401 limpia el token y devuelve al login, por el mismo camino que el resto de la app.
- **Sin hábitos hoy:** estado vacío que aclara que no hay hábitos programados para hoy, distinto de "todavía no creaste hábitos".

## 5. Especificación de Comportamiento BDD

```gherkin
Escenario: Un toque suma uno a un hábito de sí o no
  Dado que tengo un hábito de sí/no programado para hoy sin registrar
  Cuando toco el botón de sumar
  Entonces el acumulado del día pasa a 1
  Y el hábito queda marcado como cumplido

Escenario: Un hábito cuantitativo avanza de a un toque
  Dado que tengo un hábito cuantitativo con objetivo 3 y acumulado 1
  Cuando toco el botón de sumar
  Entonces el acumulado del día pasa a 2
  Y el hábito todavía no está cumplido

Escenario: Restar corrige un toque de más
  Dado que tengo un hábito cuantitativo con objetivo 5 y acumulado 3
  Cuando toco el botón de restar
  Entonces el acumulado del día pasa a 2

Escenario: Restar no descumple un día ya cumplido
  Dado que tengo un hábito de sí/no cumplido hoy con acumulado 1
  Cuando toco el botón de restar
  Entonces el acumulado del día pasa a 0
  Y el hábito sigue marcado como cumplido
  Y mi racha no se interrumpe

Escenario: No se puede restar por debajo de cero
  Dado que tengo un hábito con acumulado 0 en el día de hoy
  Cuando intento restar
  Entonces la operación es rechazada
  Y el acumulado sigue en 0

Escenario: El día se ancla a UTC-6 y no a UTC
  Dado que son las 23:30 en la zona horaria de los hábitos
  Cuando toco el botón de sumar
  Entonces la entrada queda registrada en el día local en curso
  Y no en el día siguiente

Escenario: Un hábito archivado no se puede marcar
  Dado que tengo un hábito archivado
  Cuando intento sumarle desde la API
  Entonces la operación es rechazada

Escenario: No puedo tocar los hábitos de otra persona
  Dado que existe un hábito de otro usuario
  Cuando intento sumarle
  Entonces recibo un 404
  Y la respuesta es idéntica a la de un hábito inexistente
```

## 6. Interfaz Gráfica Sugerida

```
+------------------------------------------+
|  Hábitos de hoy                          |
+------------------------------------------+
|                                          |
|  Meditar                       cumplido  |
|  1 / 1                                   |
|                      [  -  ]  [  +  ]    |
|  --------------------------------------  |
|                                          |
|  Tomar agua                              |
|  3 / 8 vasos                             |
|                      [  -  ]  [  +  ]    |
|  --------------------------------------  |
|                                          |
|  Leer            2 de 4 días esta semana |
|  0 / 1                                   |
|                      [  -  ]  [  +  ]    |
|                                          |
|  [ ! No se pudo registrar. Reintentar ]  |
|                                          |
+------------------------------------------+

Estado vacío:

+------------------------------------------+
|  Hábitos de hoy                          |
+------------------------------------------+
|                                          |
|                  [ ]                     |
|                                          |
|      Hoy no tenés hábitos programados.   |
|      Deslizá hacia abajo para actualizar.|
|                                          |
+------------------------------------------+
```

El botón de restar aparece deshabilitado cuando el acumulado del día es 0.

## Consecuencias declaradas

Dos decisiones tienen efectos que no son obvios y quedan escritas para que nadie las "arregle" más adelante:

1. **Restar ajusta el agregado del día, no el registro de entradas.** El historial va a decir que se marcó tres veces aunque el día cierre en dos. Es deliberado: el registro es un log de acciones, no de resultados.

2. **Un día cumplido no vuelve a estar incumplido.** Esto rompe la invariante actual de que `completed` se deriva de `acumulado >= objetivo`: a partir de acá puede existir un día con acumulado 0 y cumplido verdadero. **`completed` deja de ser recalculable desde `accumulated_amount`**, y cualquier proceso que lo recalcule en lote borraría rachas legítimas.
