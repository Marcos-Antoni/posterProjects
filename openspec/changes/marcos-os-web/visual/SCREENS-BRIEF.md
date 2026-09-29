# Screens brief — Marcos OS (shared by every screen-generation agent)

## Sources of truth (read before drawing anything)
1. Design skill: `~/.claude/plugins/marketplaces/claude-plugins-official/plugins/frontend-design/skills/frontend-design/SKILL.md` — follow its quality floor and self-critique.
2. Visual system: `visual/marcos-os-design-proposal.md` + `visual/marcos-os-styleguide.html` (tokens, type scale, spacing, radii, motion, components, metro-style unlock graph). Reuse its `:root` / dark-theme token block VERBATIM; do not invent colors or fonts.
3. Behavior: the change's `design.md` (screen inventory + UX behavior) and `specs/` deltas. Web: `posterProjects/openspec/changes/marcos-os-web/`. Mobile: `posterMobile/openspec/changes/marcos-os-mobile/`.
4. Validated requirements (R1–R23) summarized in `proposal.md` of each change.

## Output format
- One self-contained static HTML file per screen: `NN-kebab-name.html` (NN = inventory number, 2 digits). Google Fonts allowed; no other external assets; inline SVG icons.
- Each file: `<title>` = screen name; a small fixed toolbar (top-right, unobtrusive) with light/dark toggle and a link back to `index.html`; a short "Nota de diseño" collapsible at the bottom (which heuristics/psychology decisions this screen applies, 3–5 bullets).
- Web screens: designed for 1440×900 desktop, must also hold at 768px. Mobile screens: render inside a 390×844 phone frame centered on the page.
- Show the screen in its most representative state; if a state matters (empty, loading, error, locked, restart-after-miss), add it as a second panel on the same page, labeled.
- UI copy in Spanish (voseo neutral-rioplatense is fine: "Empezá", "Ibas por…"), code/comments in English.

## Realistic data (use it, don't lorem-ipsum)
- Objective A: "Marcos OS en uso diario" — Marco's real 14-day program (day 1 "Mesa lista" done, day 2 "Captura 10 min" active, then Clasificar y elegir prioridad → days 4–6 parallel (Revisar video Física, Revisar fórmula cuadrática, Poster desde el teléfono) → hito "Semana 1: boceto de Ahora" → Elegir acción del lunes → Hora prevista vs real → Dos minutos → veinte → Usar "Estoy trabado" → Tres fricciones → hito "Especificar Ahora" → Backlog y primera tarea → cumbre "Revisión 11-oct").
- Objective B: "Aprobar finales" (Física II y Microeconomía; stations like "Física II: guía resuelta", "Física II: examen", "Micro: resumen y examen").
- Objective C: "Marcos OS web v1" (unlocked by hito "Especificar Ahora").
- Habits: "Gimnasio 06:30" (Mar–Vie, with a friend), "Dormir 22:00", "Leer 2 páginas de Control". Identity: "Soy alguien que construye cada día".
- Retired example: "Diseñar segundo cerebro completo" — motivo "planificar sin práctica", decisión "archivar tal cual".
- Dates in America/Guatemala; today = domingo 27 sep 2026 unless a screen needs another day.

## Non-negotiables (from the requirements and the books)
One active task at a time · 2-minute version always visible first · no red for "you failed" (red only for system errors) · missed day = dotted gap + "Retomar con 2 minutos" · silent 25-min cue (3px line + dot, no modal/sound) · celebration scales task/milestone/objective and is never variable-reward · nothing is deleted, only retired with a reason · AI proposes, Marco confirms (except minor ops) · no feeds, no badges, no engagement notifications, no infinite scroll · Nielsen's 10 heuristics applied visibly.

## Process per screen
Draft → take a headless screenshot (light AND dark) → look at it → fix what reads generic, cramped or inconsistent with the styleguide → save. Keep screenshots out of the repo (use the session scratchpad).
