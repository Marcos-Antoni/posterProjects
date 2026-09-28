# Marcos OS — Propuesta de diseño visual general

Versión 1 · 2026-09-27 · Alcance: web (Laravel/Inertia/React + Tailwind v4 + shadcn/ui) y, después, Flutter.
Restricción D-017 respetada: esto es diseño, no implementación.

---

## 0. Grounding del brief

- **Sujeto:** un sistema personal que convierte objetivos en un mapa de pasos pequeños y ayuda a Marco a *empezar* y *terminar* un paso a la vez.
- **Audiencia:** una sola persona. Marco: dev, hiperfoco una vez que arranca, bloqueo para arrancar cuando algo se ve grande, perfeccionismo, patrón "resetear todo al primer fallo", YouTube como escape. Le gustan los checks. Vive en Guatemala (UTC-6), hispanohablante.
- **Trabajo principal de la interfaz:** reducir la distancia entre "abrí la app" y "hice el primer movimiento físico", sin pelear con él.
- **Vocabulario del propio brief que usamos como materia prima:** *mapa* (el Objetivo es el mapa), *hito* (milestone), *cumbre* ("summit moment"), *desbloquear*, *camino*, *versión de 2 minutos*.

---

## 1. Concepto y personalidad

### La idea memorable: **"Marcas de senda"**

En un sendero de montaña nadie te muestra la ruta completa a cada paso. Te muestran **la próxima marca pintada** en una piedra o un tronco. La ves, caminás hasta ella, y desde ahí aparece la siguiente. Los hitos (en español, literalmente, el mojón de piedra que marca el camino) confirman que vas bien; la cumbre llega sola.

Marcos OS es eso: **el sistema pinta la próxima marca; Marco solo camina hasta ella.**

- Un **check** no es una palomita verde genérica: es **pintar la marca** — un trazo rectangular color ocre-señal que se "pinta" de izquierda a derecha. Es el único elemento audaz del sistema (la regla "gastá tu audacia en un solo lugar"). Todo lo demás es quieto y disciplinado.
- El **grafo de desbloqueo** es una vía de senda vertical: puntos unidos por tramos; un tramo fino gris es camino por venir, uno grueso ocre es camino recorrido.
- Un **hito** se dibuja como un mojón (triángulo de piedras apiladas); el **objetivo** como la cumbre.
- La paleta sale del paisaje volcánico de Guatemala al amanecer: niebla (fondo claro), basalto (texto y modo oscuro), pino de bosque nuboso (acción), ocre de marca de senda (check/progreso).
- La tipografía sale de la **señalética de senderos**: una sans de señalización vial para la interfaz y una slab tipo "letrero de madera tallada" para los momentos de hito/cumbre.

### Personalidad (3 adjetivos, con su contrario prohibido)

| Es | No es |
|----|-------|
| **Quieta** — informa en la periferia, nunca reclama | Ruidosa, notificadora, "¡no pierdas tu racha!" |
| **Concreta** — siempre muestra un movimiento físico | Motivacional, vaga, frases de póster |
| **Leal** — nunca borra, nunca cobra deuda, retoma donde quedaste | Juez, contador de fallas, marcador en rojo |

### Voz del texto (copy)

Verbos planos, tuteo neutro (con voseo opcional si Marco lo prefiere), sentence case, sin exclamaciones, sin halagos.
- Botón primario de Ahora: **"Empezar los 2 minutos"** → toast: **"Empezaste."**
- Check: **"Marcar hecho"** → estado: **"Hecho"**.
- Reinicio: **"Retomar con 2 minutos"** (nunca "Recuperar racha perdida").
- Retiro: **"Retirar"** → **"Retirado. Sigue visible en Retirados."**

---

## 2. Psicología → interfaz

| Principio (fuente) | Decisión concreta de UI |
|---|---|
| **B = MAP: la conducta ocurre cuando motivación, habilidad y un *prompt* coinciden; con motivación baja hace falta un prompt tipo "facilitador" que suba la habilidad** (Fogg, [behaviormodel.org](https://www.behaviormodel.org/), [prompts](https://www.behaviormodel.org/prompts)) | La vista de despertar (móvil) termina en **un solo botón de 56px** con el verbo de la versión de 2 minutos ("Abrir el archivo `plan.md`"). El prompt es el propio botón, no una notificación. Nunca se muestra la tarea completa sin su versión de 2 minutos al lado. |
| **Regla de los 2 minutos / "hacerlo fácil"** (Clear, *Atomic Habits*) | El **chip de 2 minutos** es el elemento clicable principal de la tarjeta de Ahora; la tarea completa queda como subtítulo. Empezar = empezar los 2 minutos. Terminar los 2 minutos ofrece "Seguir" o "Cerrar por hoy" con igual peso visual (ambas son victorias). |
| **Principio del progreso: el progreso en trabajo significativo, aunque sea pequeño, es lo que más mejora la vida interior del trabajo** (Amabile & Kramer, [HBR 2011](https://hbr.org/2011/05/the-power-of-small-wins)) | **El despertar muestra PRIMERO lo hecho** (ayer + esta semana) como una **tira de marcas pintadas**, no como un número grande. Cada check es visible como marca, no se agrega en porcentaje abstracto. |
| **Goal-gradient: el esfuerzo acelera cerca de la meta; la ilusión de progreso inicial también acelera** (Kivetz, Urminsky & Zheng, [JMR 2006](https://home.uchicago.edu/ourminsky/Goal-Gradient_Illusionary_Goal_Progress.pdf)) | Las barras de progreso de hito cuentan **pasos del hito**, no del objetivo entero (distancia corta = gradiente fuerte). El grafo muestra "te faltan 2 tramos para el mojón". **No** usamos progreso falso inflado (sería manipulación, ver §6). |
| **Zeigarnik NO se sostiene; Ovsiankina (tendencia a retomar lo interrumpido) sí** (Ghibellini & Meier, [meta-análisis 2025](https://www.nature.com/articles/s41599-025-05000-w)) | No usamos listas de "pendientes sin terminar" para generar tensión. Usamos lo que sí funciona: **"Retomar donde quedaste"** — la tarjeta de Ahora guarda la última nota/subpaso y lo muestra como punto exacto de reentrada (coincide con "devolveme al punto exacto, no un resumen"). |
| **Intenciones de implementación (si X, entonces Y), d ≈ 0.65** (Gollwitzer & Sheeran 2006, [resumen](https://www.researchgate.net/publication/37367696_Implementation_Intentions_and_Goal_Achievement_A_Meta-Analysis_of_Effects_and_Processes)) | Cada hábito y cada "qué puede salir mal" del plan de 5 puntos tiene un campo **"Cuando…, entonces…"** con dos inputs en línea. Se muestran como una línea en la tarjeta (reconocimiento, no recuerdo). |
| **Rachas rotas visibles reducen la participación futura; el efecto baja si la racha se puede "reparar"** (Silverman & Barasch, [JCR 2023](https://academic.oup.com/jcr/article-abstract/49/6/1095/6623414)); **perder un día no afecta la formación del hábito** (Lally et al., [EJSP 2010](https://onlinelibrary.wiley.com/doi/abs/10.1002/ejsp.674)) | **Racha tolerante "nunca fallar dos veces"**: un día faltado se dibuja como **marca en contorno (hueco), no como X ni rojo**; la racha sigue intacta. Al día siguiente aparece solo un chip "Retomar con 2 minutos" que *repara* el hueco (la marca se rellena en tono más suave). Solo dos huecos seguidos cierran el tramo — y se cierra con un "Tramo de 12 días" guardado, nunca con "0". |
| **Efecto "borrón y cuenta nueva": los hitos temporales separan las imperfecciones pasadas** (Dai, Milkman & Riis, [Management Science 2014](https://pubsonline.informs.org/doi/10.1287/mnsc.2014.1901)) | Reinicio sin deuda: cada lunes y cada mañana la vista arranca limpia ("Semana nueva"). Lo no hecho **no se arrastra como atraso**; queda en el mapa en su lugar, sin fecha vencida en rojo. |
| **Votos de identidad** (Clear) + **Goodhart: la medida que se vuelve meta deja de medir** | Resumen de progreso muestra **"12 de 15 votos por 'soy alguien que construye cada día'"** como proporción textual, **sin puntaje global, sin nivel, sin XP**. Las métricas son una por plan (la ONE metric del plan de 5 puntos), nunca un score compuesto. |
| **Teoría de la autodeterminación: autonomía, competencia, relación** (Peters, Calvo & Ryan, [Frontiers 2018](https://www.frontiersin.org/journals/psychology/articles/10.3389/fpsyg.2018.00797/full); [NN/g](https://www.nngroup.com/articles/autonomy-relatedness-competence/)) | **Autonomía:** la IA *propone*, Marco *decide* — cada propuesta mayor tiene "Aceptar / Editar / Descartar" al mismo nivel. **Competencia:** la dificultad se ajusta (Goldilocks) y se muestra "subiste de 2 a 5 min". **Relación:** sin social (req. 19); se sustituye por relación con su yo pasado — evidencia registrada en cada cumbre. |
| **Tecnología calma: pedir la mínima atención, usar la periferia, informar y calmar** (Weiser & Brown, [1995](https://people.csail.mit.edu/rudolph/Teaching/weiser.pdf); Amber Case, [principios](https://caseorganic.com/post/principles-of-calm-technology/)) | **Cue silencioso de 25 min**: una línea de 3px en el borde superior de la tarjeta de Ahora que se llena lentamente en ocre; a los 25 min aparece un punto ocre y el texto "25 min · ¿seguís en esto?" en tono *muted*. Sin sonido, sin modal, sin vibración, sin mover el foco del teclado. En móvil, solo el ícono de la barra de estado persistente. |
| ***Control*: la tecnología tipo tragamonedas roba control; tras una victoria difícil, calma** | **Recompensas fijas y proporcionales, nunca variables.** Tras un hito: pantalla quieta con la evidencia, sin confeti, sin "sigue así". El botón por defecto después de una cumbre es "Cerrar por hoy". |
| **Celebración escalada al tamaño** (requisito 10) | **Tarea:** la marca se pinta (180ms) + el tramo siguiente del grafo se traza (400ms). **Hito:** "momento cumbre" — el mojón se dibuja con un anillo de curva de nivel que se expande una vez (900ms) y se pide una línea de evidencia. **Objetivo:** ninguna animación; se abre la revisión de aprendizaje (página tranquila, tipografía slab grande, preguntas). |
| **Ley de Hick: más opciones = más tiempo de decisión** ([Laws of UX](https://lawsofux.com/hicks-law/)) | Ahora muestra **1 tarea**. Despertar muestra **1 tarea**. Cada pantalla tiene **1 acción primaria**. Prioridad semanal: 1 principal + máx. 2 estándares de mantenimiento. |
| **Ley de Fitts: el tiempo depende de distancia y tamaño del objetivo** ([Laws of UX](https://lawsofux.com/fittss-law/)) | En móvil las acciones primarias van en la **zona del pulgar** (tercio inferior), altura 56px, ancho completo. Checks de hábitos: fila entera clicable (no solo el cuadradito). |
| **Accesibilidad cognitiva: interfaz limpia, focos claros, sin límites de tiempo que generen ansiedad** (W3C COGA, [Making Content Usable](https://www.w3.org/TR/2018/WD-coga-usable-20181211)) | Ningún contador regresivo visible. Navegación siempre en el mismo orden. Un solo foco visual por pantalla. Texto de párrafo ≤ 68ch. |
| **Aversión a la pérdida potencia rachas pero produce ansiedad y abandono** (Silverman & Barasch 2023; efecto "what the hell") | Nunca mostrar "vas a perder X". Nada que se "pierda" en la UI: todo lo hecho queda pintado para siempre en el historial. |
| **Diseño de entorno** (Clear; *Control*) | La app abre **directamente** en Ahora (web) o Despertar (móvil). No hay home con feed. El botón "Estoy trabado" siempre está a un toque, en el mismo lugar. |
| **Psicología del color: evidencia incipiente, efectos dependientes del contexto; casi toda la teoría se concentra en el rojo** (Elliot, [Frontiers 2015](https://www.frontiersin.org/journals/psychology/articles/10.3389/fpsyg.2015.00368/pdf)) | No justificamos colores con "el verde calma". Lo único que sí tomamos: **el rojo en contextos de logro se asocia a evitación/amenaza** (la línea de Elliot sobre rojo) → **rojo prohibido para el comportamiento de Marco**; se reserva exclusivamente para errores del sistema (fallo de red, auth). Los demás colores se eligen por contraste, consistencia y concepto. |

### Cómo se ven los momentos clave (resumen)

- **Despertar te atrae:** abre con la tira de marcas de ayer y esta semana (algo que *ya tenés*), luego la frase de identidad, luego UNA tarea con su versión de 2 min y "esto abre: …". Lectura de arriba hacia abajo, el pulgar ya está sobre el botón.
- **Checks satisfactorios:** la marca se pinta con un trazo (no un fade), con un pequeño "ancla" tipográfica "Hecho" y, en móvil, un tic háptico ligero único (el único háptico del sistema).
- **Reinicio sin deuda:** "Hoy retomás. 2 minutos: abrir el editor." — sin mencionar cuántos días pasaron.
- **Retiro no punitivo:** diálogo sereno en tono basalto, pregunta "¿Por qué lo retirás?" y "¿Qué pasa con lo que contiene?" (Mover / Dividir / Archivar tal cual). El nodo queda **rayado (hatch) en gris cálido**, nunca tachado ni en rojo.
- **Grafo de desbloqueo:** el progreso se lee como **vía ocre gruesa que crece** hacia abajo; lo que viene es una línea fina gris con puntos chicos; la marca de hoy es el único punto con nombre.

---

## 3. Las 10 heurísticas de Nielsen aplicadas

Fuente primaria: [NN/g, "10 Usability Heuristics for User Interface Design"](https://www.nngroup.com/articles/ten-usability-heuristics/) (1994, actualizado 30-ene-2024). Fuente secundaria: [Semrush ES, David Arenzana, 25-mar-2022](https://es.semrush.com/blog/usabilidad-web-principios-jakob-nielsen/).

**Discrepancias encontradas:** el orden y los 10 nombres coinciden. Diferencias de matiz: (a) Semrush traduce #2 como "Relación entre el sistema y el mundo real" (NN/g: *match*, "coincidencia"), y la resume con "imágenes claras e información ordenada", que NN/g no enfatiza — NN/g habla de lenguaje del usuario y convenciones del mundo real; (b) Semrush resume #3 como "corregir errores sin frustración", mientras NN/g pone el foco en **"salidas de emergencia" claramente marcadas y deshacer/rehacer**; (c) en #10 Semrush lista "manuales, FAQs", mientras NN/g (versión 2024) insiste en documentación **en contexto, en el momento en que se necesita**, y enfocada en tareas; (d) Semrush no refleja la actualización de 2024 de NN/g (que reescribió ejemplos y definiciones). Usamos NN/g como autoridad.

| # | Heurística | Aplicación concreta en Marcos OS |
|---|---|---|
| 1 | Visibilidad del estado del sistema | La tarjeta de Ahora muestra siempre: en qué paso estás, hace cuánto empezaste (línea periférica de 25 min), y qué desbloquea. Las órdenes a la IA remota (ruta b) muestran estado asíncrono explícito: *En cola → Ejecutando en el VPS → Listo/Falló* con hora. |
| 2 | Coincidencia con el mundo real | Vocabulario de senda y del propio Marco: *Objetivo, Plan, Hito, Tarea, Hábito, Retirar, Retomar, Estoy trabado*. Nada de *issue, sprint, backlog, story points*. Fechas en hora de Guatemala. |
| 3 | Control y libertad del usuario | Todo check se deshace desde el toast "Hecho · Deshacer" (8 s) y desde el historial. Retirar no borra; se "Devuelve al mapa" desde Retirados. Toda propuesta de la IA es reversible hasta que Marco la acepta. |
| 4 | Consistencia y estándares | Un verbo = una acción en todo el flujo ("Retirar" → "Retirado"). La misma marca ocre significa "hecho" en tareas, hábitos, grafo y resumen. shadcn/ui como base para respetar patrones de plataforma (Dialog, Sheet, Command). |
| 5 | Prevención de errores | Retirar exige razón escrita + decisión del contenido antes de habilitar el botón (sin checkbox "¿seguro?"). Cambios mayores de la IA (crear, retirar, fechas, dependencias) siempre pasan por vista previa de diff. Las dependencias rechazan ciclos al dibujar la arista. |
| 6 | Reconocer antes que recordar | La tarjeta de Ahora recuerda el punto exacto ("Ibas por: escribir el test del caso vacío"). El "Cuando…, entonces…" del hábito se muestra en la fila del hábito. El grafo muestra etiquetas, no IDs. |
| 7 | Flexibilidad y eficiencia | Web: paleta de comandos (⌘K / Ctrl+K), atajos `C` capturar, `Espacio` marcar, `T` estoy trabado, `N` ir a Ahora. Captura rápida desde cualquier pantalla. Marco también puede operar todo por CLI/MCP. |
| 8 | Diseño estético y minimalista | Una tarea visible, una acción primaria por pantalla, sin métricas decorativas, sin gráficos de vanidad. El único color saturado del sistema es la marca ocre. |
| 9 | Reconocer, diagnosticar y recuperarse de errores | Errores del sistema en lenguaje llano con la salida: "No se pudo guardar: sin conexión. Queda en este dispositivo y se sube al volver." Nunca se culpa a Marco; los "fallos" de conducta no son errores y nunca usan este estilo. |
| 10 | Ayuda y documentación | Ayuda en contexto: cada campo del plan de 5 puntos tiene un ejemplo gris debajo tomado de *Control*. Un "?" en el grafo explica la leyenda de estados. Sin manual separado. |

---

## 4. Design tokens

### 4.1 Color

Todos los ratios calculados con la fórmula de luminancia relativa de WCAG 2.x (script en el scratchpad: `contrast.py`). Texto normal exige 4.5:1 (AA), componentes de UI y gráficos 3:1 (WCAG 1.4.11).

#### Modo claro — "Niebla de la mañana"

| Rol | Token | Hex | Contraste verificado |
|---|---|---|---|
| Fondo | `--background` | `#EEF2EF` | — |
| Superficie (tarjetas, sheets) | `--surface` / `--card` | `#FAFBF9` | — |
| Superficie hundida (tracks, inputs) | `--sunken` / `--muted` | `#E3E9E5` | — |
| Texto | `--foreground` "basalto" | `#1B2A2A` | 13.17 sobre bg · 14.34 sobre surface · 12.08 sobre sunken |
| Texto secundario | `--muted-foreground` | `#56686A` | 5.18 sobre bg · 5.65 sobre surface · 4.76 sobre sunken |
| Borde decorativo | `--border` | `#C9D3CF` | 1.48 (decorativo, nunca único indicador) |
| Borde de control (inputs) | `--input` | `#7D8C89` | 3.38 sobre surface (≥3:1 ✓) |
| Primario "pino" (acción) | `--primary` | `#1D5E4E` | 6.72 sobre bg · 7.32 sobre surface |
| Texto sobre primario | `--primary-foreground` | `#FAFBF9` | 7.32 |
| Marca / check (relleno) | `--blaze` | `#E6AE1F` | 1.78 sobre bg → **nunca solo**: siempre con borde `--blaze-ink` + glifo basalto |
| Texto sobre marca | `--blaze-foreground` | `#1B2A2A` | 7.40 |
| Tinta de marca (progreso, borde de check) | `--blaze-ink` / `--progress` | `#8A6200` | 4.85 sobre bg · 5.29 sobre surface · 4.46 sobre sunken |
| Atención sin castigo | `--attention` | `#5B4E8C` | 6.38 sobre bg · 6.94 sobre surface · 5.99 sobre su fondo |
| Fondo de atención | `--attention-bg` | `#ECE8F6` | — |
| Error del sistema (solo sistema) | `--destructive` | `#A33B2B` | 6.29 sobre surface |
| Anillo de foco | `--ring` | `#2B63D9` | 4.78 sobre bg · 5.20 sobre surface (siempre con `ring-offset` 2px del color de fondo; sobre pino solo daría 1.41) |
| Nodo bloqueado | `--node-locked` | `#7D8C89` (trazo punteado) | 3.38 sobre surface |
| Nodo disponible | `--node-available` | `#1D5E4E` (trazo sólido, relleno surface) | 7.32 |
| Nodo activo | `--node-active` | `#E6AE1F` relleno + `#8A6200` borde 2px | borde 5.29 |
| Nodo hecho | `--node-done` | `#1D5E4E` relleno + glifo `#FAFBF9` | 7.32 |
| Nodo retirado | `--node-retired` | `#7D8C89` trazo + relleno rayado (hatch) 45° | 3.38; etiqueta en `--muted-foreground` |

#### Modo oscuro — "Noche en el volcán"

No es el "negro con un acento ácido" genérico: el fondo es basalto verdoso (#142022), y el acento es ocre cálido con un segundo color (pino claro) que carga la acción.

| Rol | Token | Hex | Contraste verificado |
|---|---|---|---|
| Fondo | `--background` | `#142022` | — |
| Superficie | `--surface` / `--card` | `#1C2A2C` | — |
| Hundida | `--sunken` / `--muted` | `#0F1819` | — |
| Texto | `--foreground` | `#E3ECE8` | 13.84 sobre bg · 12.30 sobre surface · 14.96 sobre sunken |
| Texto secundario | `--muted-foreground` | `#9FB2AE` | 7.51 · 6.67 · 8.11 |
| Borde decorativo | `--border` | `#33464A` | decorativo |
| Borde de control | `--input` | `#6F8581` | 3.77 sobre surface |
| Primario | `--primary` | `#6CC3A6` | 7.94 sobre bg · 7.05 sobre surface |
| Texto sobre primario | `--primary-foreground` | `#142022` | 7.94 |
| Marca / check | `--blaze` | `#F0BF45` | 9.72 sobre bg · 8.64 sobre surface |
| Texto sobre marca | `--blaze-foreground` | `#142022` | 9.72 |
| Tinta de marca / progreso | `--blaze-ink` / `--progress` | `#F0BF45` | igual que marca |
| Atención | `--attention` | `#A99CE0` | 6.76 sobre bg · 5.62 sobre su fondo |
| Fondo de atención | `--attention-bg` | `#2A2A45` | — |
| Error del sistema | `--destructive` | `#F08A78` | 6.08 sobre surface |
| Foco | `--ring` | `#7FA8FF` | 7.10 sobre bg · 6.31 sobre surface |
| Nodo bloqueado | `--node-locked` | `#6F8581` punteado | 3.77 |
| Nodo disponible | `--node-available` | `#6CC3A6` trazo | 7.05 |
| Nodo activo | `--node-active` | `#F0BF45` relleno | 8.64 |
| Nodo hecho | `--node-done` | `#6CC3A6` relleno + glifo `#142022` | 7.94 |
| Nodo retirado | `--node-retired` | `#6F8581` + hatch | 3.77 |

**Regla de color no negociable:** ningún estado se comunica solo por color. Hecho = relleno + glifo check; bloqueado = punteado + candado; retirado = hatch + ícono de archivo; activo = relleno ocre + anillo.

#### Tailwind v4 + shadcn (CSS)

```css
@import "tailwindcss";

:root {
  --background: #EEF2EF;  --foreground: #1B2A2A;
  --card: #FAFBF9;        --card-foreground: #1B2A2A;
  --popover: #FAFBF9;     --popover-foreground: #1B2A2A;
  --primary: #1D5E4E;     --primary-foreground: #FAFBF9;
  --secondary: #E3E9E5;   --secondary-foreground: #1B2A2A;
  --muted: #E3E9E5;       --muted-foreground: #56686A;
  --accent: #E3E9E5;      --accent-foreground: #1B2A2A;
  --destructive: #A33B2B; --destructive-foreground: #FAFBF9;
  --border: #C9D3CF;      --input: #7D8C89;  --ring: #2B63D9;
  --blaze: #E6AE1F;       --blaze-foreground: #1B2A2A; --blaze-ink: #8A6200;
  --attention: #5B4E8C;   --attention-bg: #ECE8F6;
  --node-locked: #7D8C89; --node-available: #1D5E4E; --node-active: #E6AE1F;
  --node-done: #1D5E4E;   --node-retired: #7D8C89;
  --line-1: #1D5E4E; --line-2: #5B4E8C; --line-3: #9C4A6E; /* líneas de objetivo en el mapa */
  --radius: 0.625rem; /* 10px — radio de tarjeta; los demás derivan */
  --shadow-color: 180 20% 14%;
}
.dark {
  --background: #142022;  --foreground: #E3ECE8;
  --card: #1C2A2C;        --card-foreground: #E3ECE8;
  --popover: #1C2A2C;     --popover-foreground: #E3ECE8;
  --primary: #6CC3A6;     --primary-foreground: #142022;
  --secondary: #0F1819;   --secondary-foreground: #E3ECE8;
  --muted: #0F1819;       --muted-foreground: #9FB2AE;
  --accent: #0F1819;      --accent-foreground: #E3ECE8;
  --destructive: #F08A78; --destructive-foreground: #142022;
  --border: #33464A;      --input: #6F8581;  --ring: #7FA8FF;
  --blaze: #F0BF45;       --blaze-foreground: #142022; --blaze-ink: #F0BF45;
  --attention: #A99CE0;   --attention-bg: #2A2A45;
  --node-locked: #6F8581; --node-available: #6CC3A6; --node-active: #F0BF45;
  --node-done: #6CC3A6;   --node-retired: #6F8581;
  --line-1: #6CC3A6; --line-2: #A99CE0; --line-3: #E08FB2;
  --shadow-color: 190 40% 3%;
}
@theme inline {
  --color-background: var(--background);  --color-foreground: var(--foreground);
  --color-card: var(--card);  --color-card-foreground: var(--card-foreground);
  --color-primary: var(--primary);  --color-primary-foreground: var(--primary-foreground);
  --color-muted: var(--muted);  --color-muted-foreground: var(--muted-foreground);
  --color-border: var(--border);  --color-input: var(--input);  --color-ring: var(--ring);
  --color-destructive: var(--destructive);
  --color-blaze: var(--blaze);  --color-blaze-foreground: var(--blaze-foreground);
  --color-blaze-ink: var(--blaze-ink);
  --color-attention: var(--attention);  --color-attention-bg: var(--attention-bg);
  --font-sans: "Overpass", ui-sans-serif, system-ui, sans-serif;
  --font-display: "Zilla Slab", ui-serif, Georgia, serif;
  --radius-xs: 2px; --radius-sm: 6px; --radius-md: 10px; --radius-lg: 16px; --radius-xl: 24px;
  --ease-paint: cubic-bezier(0.65, 0, 0.35, 1);
  --ease-out-soft: cubic-bezier(0.22, 1, 0.36, 1);
}
```

### 4.2 Tipografía

| Rol | Familia (Google Fonts) | Por qué |
|---|---|---|
| Interfaz y cuerpo | **Overpass** (400, 600, 700; `font-variant-numeric: tabular-nums` en contadores) | Derivada de *Highway Gothic*, la tipografía de señalización vial: nació para leerse de un vistazo y en movimiento. Es la voz de "la próxima marca". Latin-ext completo (ñ, tildes). |
| Momentos de hito, cumbre y títulos de objetivo | **Zilla Slab** (500, 600) | Slab con aire de letrero de madera tallada de parque. Solo aparece en títulos de objetivo, momento cumbre y revisión de aprendizaje: su rareza hace que "suene" a logro. |

Sin monoespaciada para etiquetas. Sin mayúsculas sostenidas para etiquetas. Base 16px, escala de tercera mayor (×1.25) redondeada a la retícula de 4px en line-height.

| Token | px / rem | line-height | Peso | Familia | Uso |
|---|---|---|---|---|---|
| `text-xs` | 12 / 0.75 | 16px | 600 | Overpass | Metadatos de nodo en grafo, contador de chips |
| `text-sm` | 14 / 0.875 | 20px | 400 / 600 | Overpass | Texto secundario, labels de formulario |
| `text-base` | 16 / 1 | 24px | 400 | Overpass | Cuerpo, filas de check |
| `text-lg` | 20 / 1.25 | 28px | 600 | Overpass | Título de tarea en tarjetas |
| `text-xl` | 24 / 1.5 | 32px | 700 | Overpass | Tarea activa en Ahora (móvil) |
| `text-2xl` | 30 / 1.875 | 36px | 700 | Overpass | Tarea activa en Ahora (web) |
| `display-sm` | 30 / 1.875 | 36px | 600 | Zilla Slab | Título de objetivo |
| `display-md` | 38 / 2.375 | 44px | 600 | Zilla Slab | Nombre del hito en el momento cumbre |
| `display-lg` | 48 / 3 | 52px | 500 | Zilla Slab | Revisión de aprendizaje del objetivo |

- Tracking: 0 en cuerpo; −0.01em en ≥24px; Zilla Slab −0.005em.
- **Largo de línea máximo:** 68ch para prosa (evidencia, revisión); 45ch para el título de la tarea activa (se lee como un letrero).
- Alineación: todo a la izquierda. Solo centrado en el momento cumbre (es un "letrero" único).

### 4.3 Espaciado y retícula

- **Base 4px.** Escala: `1=4, 2=8, 3=12, 4=16, 5=20, 6=24, 8=32, 10=40, 12=48, 16=64, 24=96`.
  Justificación: coincide con Tailwind (`--spacing: 0.25rem`) y con la grilla de 4dp de Flutter/Material, así la versión móvil hereda los mismos números.
- **Ritmo:** dentro de un componente 8–16px; entre componentes 24px; entre secciones 48px (web) / 32px (móvil).
- **Web:** contenedor de Ahora centrado a **640px** de ancho (una columna: es una tarea, no un dashboard). Mapa/grafo: ancho completo con panel lateral de 360px (detalle del nodo). Grilla de 12 columnas, gutter 24px, margen 32px (≥1024px) / 24px (≥640px).
- **Móvil:** 4 columnas, gutter 16px, **margen lateral 16px**. Acción primaria anclada a 16px del borde inferior + safe-area.

### 4.4 Radios por jerarquía

| Token | Valor | Dónde |
|---|---|---|
| `radius-xs` | 2px | **La marca** (check, chips de marca, tira semanal) — pintura sobre piedra, casi recta |
| `radius-sm` | 6px | Botones, inputs, chip de 2 minutos |
| `radius-md` | 10px | Tarjetas (tarea en Ahora, hábito), nodos de tarea en grafo |
| `radius-lg` | 16px | Sheets, diálogos, panel de IA |
| `radius-xl` | 24px | Sheet inferior en móvil (solo esquinas superiores) |
| `full` | 9999px | Solo avatares/puntos de estado; **nunca** en botones |

### 4.5 Elevación

Sistema mayormente plano: la jerarquía la dan fondo/superficie/hundido. Sombras teñidas de basalto, nunca `rgba(0,0,0,.1)` gris.

| Nivel | Uso | Valor |
|---|---|---|
| 0 | Fondo, filas | ninguna |
| 1 | Tarjeta de Ahora (única tarjeta elevada en la pantalla) | `0 1px 2px hsl(var(--shadow-color)/.10), 0 4px 12px hsl(var(--shadow-color)/.06)` |
| 2 | Popover, menú, command palette | `0 8px 24px hsl(var(--shadow-color)/.14)` |
| 3 | Diálogo, sheet | `0 16px 48px hsl(var(--shadow-color)/.20)` + overlay basalto 40% |

En oscuro, la elevación se expresa por superficie más clara (`#1C2A2C` sobre `#142022`) más la sombra.

### 4.6 Movimiento

| Momento | Duración | Easing | Qué pasa |
|---|---|---|---|
| Pintar la marca (check) | 180ms | `--ease-paint` | `clip-path`/`scaleX` de 0→1 desde la izquierda + glifo aparece a los 120ms |
| Trazar el tramo desbloqueado | 400ms | `--ease-out-soft` | `stroke-dashoffset` del tramo; el nodo siguiente pasa de punteado a sólido |
| Momento cumbre (hito) | 900ms, una vez | `--ease-out-soft` | Anillo de curva de nivel se expande y se desvanece; el nombre en Zilla Slab aparece sin desplazarse |
| Objetivo cerrado | 0ms | — | Sin animación; transición de página normal (celebración = reflexión) |
| Llenado del cue de 25 min | 25 min lineal | `linear` | Línea de 3px; imperceptible como movimiento |
| Aparición del punto de 25 min | 600ms | ease-out | Opacidad 0→1, sin escala, sin rebote |
| Abrir sheet/diálogo | 200ms | `--ease-out-soft` | Opacidad + 8px |
| Hover/pressed | 100ms | ease-out | Solo color de fondo; pressed baja 1px |

**No se anima:** entradas de sección, listas, hover de tarjetas, números que cuentan, confeti, loaders decorativos.

**`prefers-reduced-motion: reduce`:** la marca aparece pintada al instante (cambio de color), el tramo pasa a sólido sin trazado, el anillo cumbre se sustituye por un borde fijo de 2px en ocre durante 2 s, sheets sin desplazamiento (solo opacidad 120ms). El háptico se mantiene (no es movimiento visual). Cumple [WCAG 2.3.3](https://www.w3.org/WAI/WCAG22/Understanding/animation-from-interactions.html).

### 4.7 Tamaños táctiles

- Mínimo absoluto WCAG 2.5.8: 24×24 CSS px; trabajo real: **44×44 (iOS) / 48×48dp (Material)** ([resumen](https://tetralogical.com/blog/2022/12/20/foundations-target-size/)).
- Marcos OS: **48px mínimo** para todo control táctil; **56px** para la acción primaria móvil y filas de check de hábitos; separación mínima 8px entre objetivos adyacentes.
- Web: botones 40px de alto (36px en densidad compacta del grafo), área clicable del check = fila completa.

### 4.8 Iconografía

- **Lucide** (ya viene con shadcn), trazo 1.75px, tamaños 16/20/24.
- Tres glifos propios, dibujados en el mismo estilo: **marca** (rectángulo con check), **mojón** (tres piedras apiladas = hito), **cumbre** (pico con bandera = objetivo).
- Íconos siempre acompañados de texto salvo en la barra de acciones del grafo (con tooltip y `aria-label`).
- Prohibidos: fueguitos de racha, trofeos, medallas, coronas, estrellas.

### 4.9 Estados

| Estado | Tratamiento |
|---|---|
| Hover (web) | Fondo `--muted` en filas; primario oscurece 8% (`color-mix`) |
| Focus | Anillo 2px `--ring` + offset 2px del color de fondo; visible siempre con teclado (`:focus-visible`) |
| Pressed | Translación 1px abajo + primario oscurece 14% |
| Disabled | Opacidad 50% **y** motivo visible en texto al lado ("Escribí una razón para retirar") |
| Loading | Botón mantiene ancho, texto cambia a gerundio ("Guardando…"), sin spinner decorativo; órdenes a la IA remota muestran pasos de estado |
| Empty | Invitación a actuar, con el primer movimiento: "No hay tarea activa. Elegí una marca del mapa o capturá una idea." + botón |
| Error (sistema) | `--destructive`, qué pasó + qué hacer + qué se conservó |
| "Faltó un día" (conducta) | **No es error.** Marca en contorno (hueco), texto `--muted-foreground`, chip "Retomar con 2 minutos" en `--attention` |
| Bloqueado (grafo) | Punto hueco chico + línea fina gris; "Se abre al terminar: X" en el panel al elegirlo |
| Retirado | Hatch + "Retirado el 12 oct · razón: …" |

---

## 5. Principios de componentes

1. **Check item (la marca).** Fila completa clicable, 56px en móvil. A la izquierda la marca: rectángulo 28×20px, `radius-xs`. Vacía = contorno `--blaze-ink` 2px; hecha = relleno `--blaze` + borde `--blaze-ink` + check basalto; faltada-y-reparable = contorno punteado. Al marcar: pintar (180ms) + toast "Hecho · Deshacer". Sin sonido. Es el elemento más cuidado del sistema.
2. **Tarjeta de tarea en Ahora.** Única tarjeta elevada. Orden fijo: (a) línea periférica de 25 min en el borde superior; (b) contexto pequeño: "Hito: Primera versión del API · Objetivo: Marcos OS"; (c) **título de la tarea** 30px; (d) chip de 2 minutos (acción principal); (e) "Ibas por: …" si hay punto de reentrada; (f) "Esto abre: …" con miniatura del siguiente nodo; (g) fila inferior: "Marcar hecho" (primario) · "Estoy trabado" (secundario). Nunca más de una tarjeta de tarea.
3. **Chip de versión de 2 minutos.** Botón con fondo `--blaze` a 20% sobre surface, borde `--blaze-ink`, texto basalto, ícono de marca a la izquierda, `radius-sm`, 48px de alto. Texto siempre verbo físico ("Abrir `routes/api.php`"). Si falta la versión de 2 minutos, el chip dice "Definir 2 minutos" y la IA propone una.
4. **Botón "Estoy trabado".** Secundario (contorno), mismo lugar siempre (abajo a la derecha en web, segundo botón del sheet en móvil), atajo `T`. Al tocarlo no abre un chat: devuelve **una** acción más pequeña en la misma tarjeta, con "Probar esta" / "Otra más chica". La IA lo resuelve sola (acción menor, req. 17).
5. **Cue silencioso.** Línea de 3px `--blaze-ink` sobre track `--sunken` en el borde superior de la tarjeta; a los 25 min, punto ocre + texto muted "25 min · ¿Seguís en esto?" con tres botones de texto: "Sigo" (descarta el aviso hasta el próximo tramo de 25 min) · "Terminé" (misma acción que "Marcar hecho") · "Estoy trabado" (mismo flujo que el botón de trabado). No roba foco, no bloquea, no hay modal, no hay sonido, no hay háptico en móvil. `aria-live="off"`: no se anuncia a lectores de pantalla (es periférico por diseño), pero los tres botones tienen nombre accesible y se alcanzan con teclado en el orden normal de tabulación.
6. **Grafo de desbloqueo: vía vertical mínima** (v3, 2026-09-27). **Referencia de Marco:** una imagen de una vía vertical en un solo color ocre, líneas gruesas y redondeadas, círculos con check, ramas paralelas que se separan y vuelven, y el objetivo abajo como un círculo con un triángulo. Su pedido: "solo puntos y lo que sucede", sin cajas, sin texto de más, sin punteado en todo y sin leyenda protagonista. Reemplaza a la v2 (mapa de metro con etiquetas a 45°), que seguía siendo ruidosa.
   - **Forma:** una vía vertical que baja; lo hecho arriba, el objetivo abajo. Tramos solo a 0°, 45° y 90° con curvas redondeadas. Las tareas que no dependen entre sí se abren en ramas paralelas a la derecha y se juntan en el hito siguiente (días 4 a 6 del programa).
   - **Lenguaje de estados (pocos medios):**
     - **Hecha:** círculo ocre lleno con check oscuro; el tramo que llega es ocre, grueso (7px) y sólido.
     - **Ahora (activa):** aro ocre grueso (4.5px) con la marca chica adentro. Es el único punto con nombre visible por defecto.
     - **Disponible:** aro ocre fino (2.5px), sin relleno; el tramo que llega es ocre y más fino (≈3px).
     - **Por venir (bloqueada):** punto hueco chico con trazo `--node-locked`; línea fina gris de 2.5px, sin punteado (el grosor y el tono ya la separan de lo recorrido).
     - **Hito:** el mismo punto en el mismo estado, solo más grande (×1.4). Sin formas nuevas.
     - **Objetivo:** círculo con un triángulo lleno adentro, al final de la vía, con su nombre en Zilla Slab.
     - **Retirado:** no ocupa la vía. Una marca mínima de 12px al costado, enfocable, con la razón en el panel lateral; el interruptor "Mostrar retirados" la oculta.
   - **Texto:** solo la tarea activa y el objetivo llevan nombre. El resto se nombra al pasar el cursor, al tabular (anillo de foco + etiqueta) o al elegir el punto, que abre el panel lateral. Cada punto es un `<g role="button" tabindex="0">` con `aria-label` y `<title>`, y el SVG trae `<title>`/`<desc>` con el recorrido completo: ocultar texto no le quita nada al lector de pantalla.
   - **Color:** en la vista de un objetivo, un solo color (`--blaze-ink` en claro para pasar 3:1; `--blaze` en oscuro). En la vista global, cada objetivo es una vía vertical lado a lado con su tono (`--line-1`, `--line-2`, `--line-3`) y el mismo lenguaje; la marca de "ahora" sigue siendo ocre. El desbloqueo entre objetivos es **un solo conector**: una línea fina desde el hito Especificar "Ahora" hasta la primera estación de Marcos OS web v1.
   - **Panel lateral:** aparece solo al elegir un punto (Esc o × lo cierra). El filtro por plan u objetivo atenúa lo demás en vez de esconderlo. Contraer un objetivo se ofrece en el panel de su objetivo.
   - **Leyenda:** una línea chica al pie, cinco símbolos (Hecha, Ahora, Disponible, Por venir, Objetivo), nunca protagonista.
   - **Librería en producción (web):** React Flow (xyflow) con layout **ELK `layered`** en dirección `DOWN` y un *custom edge* que ajusta los codos a 45° y redondea las esquinas; nodos custom en SVG. Alternativa: layout propio en SVG/d3. **Tradeoff:** React Flow + ELK da zoom, pan, selección, teclado y layout automático gratis, pero hay que escribir el router de aristas para este look; el SVG propio es exacto y liviano, pero la interacción y la accesibilidad se construyen a mano.
   - **Flutter:** `CustomPainter` dentro de un `InteractiveViewer`, con las coordenadas que ya calculó ELK (el JSON de layout viaja por la API). **Tradeoff:** `graphview` trae layouts (Sugiyama) listos, pero sus aristas no respetan 0/45/90° ni las curvas; `CustomPainter` es más trabajo y es fiel al diseño.

7. **Resumen de progreso.** Tira horizontal de 7 marcas (esta semana) + ayer destacado; debajo, por hábito: "Tramo de 12 días · 1 hueco reparado". Votos de identidad como frase: "12 votos de 15 por *soy alguien que entrena*". Sin porcentajes globales, sin gráficos de líneas, sin comparación con otras semanas en rojo/verde.
8. **Input de captura.** Siempre accesible (`C` en web; botón fijo en móvil). Una línea, Enter guarda al inbox, confirmación "Capturado". No pide prioridad, fecha ni proyecto (captura ≠ prioridad). Tras guardar, el foco vuelve a donde estaba (Marco no pierde el punto).
9. **Diálogo de retiro.** Título "Retirar *nombre*". Dos pasos en la misma vista: "¿Por qué lo retirás?" (textarea, obligatorio) y "¿Qué hacés con lo que contiene?" (radio: Mover a… / Dividir / Archivar tal cual). Botón "Retirar" en `--primary` (no destructivo: no se borra nada), habilitado cuando ambos están completos. Nota al pie: "Nada se borra. Lo vas a ver en Retirados."
10. **Panel de negociación con la IA.** Sheet lateral de 480px (web). La propuesta se muestra como **árbol editable** (no como chat): cada nodo con "Aceptar / Editar / Quitar". Cambios mayores con diff (añadido en `--primary`, quitado en `--muted-foreground` tachado — nunca rojo). Botón final "Guardar este mapa" solo cuando Marco marca "Estamos de acuerdo". Las órdenes remotas (ruta b) muestran estado asíncrono y pueden cancelarse.

---

## 6. Anti-patrones prohibidos

1. **Rojo para vencido, atrasado o faltado.** Rojo solo para errores del sistema. (Req. 12; Elliot sobre rojo y evitación.)
2. **Rachas que vuelven a 0** con un solo fallo. (Req. 11; Silverman & Barasch 2023; Lally 2010.)
3. **"Vas a perder…"**, cuentas regresivas, urgencia artificial, aversión a la pérdida como palanca.
4. **Recompensas variables** (cofres, sorpresas, confeti aleatorio): mecánica de tragamonedas (*Control*; crítica al modelo Hook).
5. **Feeds, scroll infinito, "para ti", notificaciones de engagement** (*Control*; Atomic Habits: diseño del entorno).
6. **Puntajes compuestos, XP, niveles, story points, velocity** (Goodhart).
7. **Varias tareas "en progreso"** a la vez; tableros kanban.
8. **Grooming de backlog como falso progreso**: no hay pantalla que invite a reordenar listas largas.
9. **Borrado real** de cualquier elemento; tachados agresivos; papelera que se vacía.
10. **Modales o sonidos para el check-in de 25 min**; robar el foco.
11. **Progreso falso** (barras que arrancan en 20% sin trabajo real): el goal-gradient se usa acortando la meta, no mintiendo.
12. **Culpa en el copy**: "¡No te rindas!", "Ayer fallaste", "Llevas 3 días sin…".
13. **Plantilla de IA**: eyebrows en MAYÚSCULAS espaciadas, `A · B · C` como decoración, "→" pegado a botones, un solo radio para todo, sombras grises idénticas, gradientes decorativos, crema + serif + terracota.
14. **Muros de tarjetas iguales** (dashboard SaaS): cada pantalla tiene una jerarquía con un protagonista.

---

## 7. Wireframes ASCII (3 pantallas clave)

### 7.1 Web — Ahora

```
┌───────────────────────────────────────────────────────────────────────────────┐
│ Marcos OS   Ahora   Mapa   Hábitos   Retirados          [ Capturar  C ]  ⌘K    │
├───────────────────────────────────────────────────────────────────────────────┤
│                                                                               │
│            ┌──────────────── 640px ────────────────────────┐                  │
│            │▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░░░░░░░░░░░░░░░░░  (cue 25m) │                  │
│            │                                                │                  │
│            │ Hito: Primera versión del API                  │                  │
│            │                                                │                  │
│            │ Escribir el endpoint de check de hábito        │  ← 30px Overpass │
│            │                                                │                  │
│            │ ┌────────────────────────────────────────┐     │                  │
│            │ │ ▰ 2 min: abrir routes/api.php          │     │  ← chip marca    │
│            │ └────────────────────────────────────────┘     │                  │
│            │                                                │                  │
│            │ Ibas por: el test del caso "día faltado"       │  ← reentrada     │
│            │                                                │                  │
│            │ Esto abre:  ┄┄┄▶ [ Sincronizar con móvil ]     │                  │
│            │                                                │                  │
│            │ ┌──────────────────┐        ┌───────────────┐  │                  │
│            │ │  Marcar hecho    │        │ Estoy trabado │  │                  │
│            │ └──────────────────┘        └───────────────┘  │                  │
│            └────────────────────────────────────────────────┘                  │
│                                                                               │
│            Esta semana   ▰ ▰ ▱ ▰ ▰ · ·      Prioridad: Marcos OS               │
│                                                                               │
└───────────────────────────────────────────────────────────────────────────────┘
 ▰ marca pintada   ▱ hueco reparable   · día por venir
```

### 7.2 Web — Grafo del objetivo (vía vertical)

La versión dibujada está en `marcos-os-styleguide.html` (sección "Grafo de desbloqueo") y en las pantallas web 08 y 09 y móvil 09, con los datos reales del programa de 14 días.

```
┌───────────────────────────────────────────────────────────────────────────────┐
│ Marcos OS   Ahora   Objetivos   Mapa   Hábitos   Revisiones   Retirados   ⌘K  │
├───────────────────────────────────────────────────────────────────────────────┤
│ Marcos OS en uso diario (Zilla)                          [Esta línea|Global]  │
│ Plan [Todos|Semana 1|Semana 2|Cierre]  (o) Mostrar retirados   Faltan 5 est.  │
│ ┌───────────────────────────────────────────────────────────────────────────┐ │
│ │                                ●  (hecha)                                 │ │
│ │                               -┃                                          │ │
│ │                                ◉  Captura 10 min                          │ │
│ │                                │  ahora                                   │ │
│ │                                ∘                                          │ │
│ │                                │╲╲                                        │ │
│ │                                ∘ ∘ ∘   (días 4 a 6, en paralelo)          │ │
│ │                                │╱╱                                        │ │
│ │                                ○   (hito, más grande)                     │ │
│ │                                ∘                                          │ │
│ │                                ∘   … días 8 a 12                          │ │
│ │                                ○   (hito)                                 │ │
│ │                                ∘                                          │ │
│ │                                ⊙▲  Revisión 11-oct                        │ │
│ └───────────────────────────────────────────────────────────────────────────┘ │
│  ● Hecha   ◉ Ahora   ○ Disponible   ∘ Por venir   ⊙▲ Objetivo                 │
└───────────────────────────────────────────────────────────────────────────────┘
 ━ ┃ tramo recorrido (ocre, grueso)   │ ╲ ╱ por venir (fino, gris)   - retirado al costado
 Al elegir un punto aparece el panel lateral de 360px; sin selección, la vía ocupa todo el lienzo.
```

### 7.3 Móvil — Despertar

```
┌───────────────────────────┐
│ Buenos días, Marco.   6:34│
│                           │
│ Ayer                      │
│ ▰ ▰ ▰   3 marcas          │  ← lo hecho primero
│                           │
│ Esta semana               │
│ ▰ ▰ ▱ ▰ ▰ · ·             │
│ Hábito "Entrenar"         │
│ Tramo de 12 días          │
│ 1 hueco reparado          │
│                           │
│ 12 de 15 votos por        │
│ "soy alguien que          │
│  construye cada día"      │
│ ───────────────────────── │
│ Hoy, una marca:           │
│                           │
│ Escribir el endpoint de   │  ← 24px
│ check de hábito           │
│ Esto abre: Sincronizar    │
│ con móvil                 │
│                           │
│┌─────────────────────────┐│
││ ▰ 2 min: abrir          ││  ← 56px, zona del pulgar
││   routes/api.php        ││
│└─────────────────────────┘│
│  Estoy trabado   Capturar │  ← 48px
└───────────────────────────┘
```

---

## 8. Autocrítica: qué cambié del primer plan y por qué

**Plan v1 (descartado en partes):**
- Color: verde esmeralda de éxito + menta + fondo blanco; check verde con palomita; modo oscuro casi negro `#0F0F0F` con acento ámbar.
- Tipo: Inter para todo.
- Despertar: un número grande de racha ("12" con ícono de fuego) arriba con gradiente.
- Ahora: tarjetas de "pendientes sin terminar" debajo de la activa, justificadas con el efecto Zeigarnik.
- Celebración: confeti en hitos.

**Revisión contra el brief y contra los defaults genéricos:**
1. **Verde de éxito + Inter + tarjetas** es el kit SaaS por defecto (trait 4 del skill): cualquier app de hábitos llega ahí. → Lo reemplacé por el concepto *marcas de senda*: el check es una marca ocre pintada, el verde pasa a ser "pino", la acción, no "éxito". La tipografía sale de la señalética de senderos (Overpass + Zilla Slab).
2. **Casi-negro + un acento cálido** es literalmente el trait 2. → El modo oscuro pasa a basalto verdoso `#142022` con dos acentos con rol distinto (pino = acción, ocre = hecho).
3. **Número grande de racha con gradiente** es el hero por defecto (número grande + label chico) y además activa aversión a la pérdida y Goodhart. → Tira de marcas + "Tramo de 12 días" + votos de identidad en texto.
4. **Zeigarnik:** la investigación reciente (meta-análisis 2025) no encuentra ventaja de memoria para tareas inconclusas, y mostrar pendientes genera la ansiedad de acumulación que Marco describe. → Cambié a **Ovsiankina** (retomar), que sí se sostiene: un único "Ibas por…", nada de lista.
5. **Confeti** es recompensa variable/estímulo (anti-*Control*) y contradice "calma tras una victoria difícil". → Celebración escalada y quieta: pintar / trazar / cumbre / reflexión.
6. **Color con "psicología"** (v1 justificaba "verde = calma"): la revisión de Elliot muestra que la evidencia es incipiente. → Quité esas justificaciones; mantuve solo la regla sobre el rojo en contextos de logro y el resto se decide por contraste y concepto.
7. **El ocre sobre fondo claro da 1.78:1**, falla 1.4.11. → Agregué `--blaze-ink #8A6200` como borde obligatorio y glifo basalto; el estado nunca depende solo del relleno.
8. **Grafo, segunda vuelta (revisión de Marco):** la v1 era un diagrama de flujo de cajas con flechas: legible, pero sin alma y sin escala. Lo rehice como mapa de metro (Beck: 0/45/90°, estaciones equidistantes) fusionado con las marcas de senda, con datos reales. Críticas que corregí tras las capturas: el ramal retirado se leía como ticks sueltos (ahora es un ramal rayado con barra final), el tachado en su etiqueta contradecía la regla de no castigar (quitado), y la bandera de la terminal chocaba con la línea que llega desde arriba (ahora el color de la línea va dentro de la base del triángulo).
9. **Grafo, tercera vuelta (referencia de Marco):** la v2 de metro seguía cargada: etiquetas a 45° en cada estación, punteado en todo lo futuro, cruces con casing y una leyenda de diez símbolos. Marco mandó una imagen de referencia (vía vertical, un color, círculos con check, ramas que vuelven, objetivo abajo) y pidió "solo puntos y lo que sucede". Pasé a un lenguaje de cinco estados por forma y grosor, nombre solo en la activa y el objetivo, y el resto al pasar el cursor o tocar. También corregí un error de estado: con Captura 10 min activa, Clasificar y elegir prioridad aparecía disponible; ahora está bloqueada, porque depende de ella.
10. **Chanel:** saqué la textura de curvas de nivel de fondo que tenía en el grafo (decoración); queda solo como el anillo único del momento cumbre.

## Qué puede salir mal

- **Overpass en tamaños chicos** tiene números algo estrechos; verificar legibilidad de `text-xs` en el grafo en pantallas de baja densidad. Plan B: subir a 13px o usar **Atkinson Hyperlegible** solo para metadatos.
- **El concepto de senda puede volverse temático de más** (íconos de montaña por todos lados). Regla: la metáfora vive en la marca, el mojón y la cumbre; nada más.
- **El cue de 25 min puede ser demasiado sutil** y Marco hiperfocalizado no lo ve nunca. Riesgo aceptado: decidido sin háptico en móvil y sin anuncio a lectores de pantalla; validar en los 14 días de D-017 si el visual alcanza.
- **Ocre + pino en modo oscuro** puede leerse "militar/camuflaje" si se abusa del verde; mantener el pino solo en acciones y aristas.
- **shadcn usa `--radius` único** por defecto; hay que sobreescribir por componente (botón `sm`, tarjeta `md`, diálogo `lg`) o se vuelve el kit genérico.
- **Flutter** no tiene `prefers-reduced-motion`: mapear a `MediaQuery.disableAnimations`.

## Fuentes

- Nielsen, NN/g — https://www.nngroup.com/articles/ten-usability-heuristics/
- Semrush ES — https://es.semrush.com/blog/usabilidad-web-principios-jakob-nielsen/
- Fogg Behavior Model — https://www.behaviormodel.org/ · https://www.behaviormodel.org/prompts
- Peters, Calvo & Ryan 2018 (METUX) — https://www.frontiersin.org/journals/psychology/articles/10.3389/fpsyg.2018.00797/full
- NN/g, Autonomy, Relatedness, Competence — https://www.nngroup.com/articles/autonomy-relatedness-competence/
- Ghibellini & Meier 2025, Zeigarnik/Ovsiankina — https://www.nature.com/articles/s41599-025-05000-w
- Kivetz, Urminsky & Zheng 2006, goal-gradient — https://home.uchicago.edu/ourminsky/Goal-Gradient_Illusionary_Goal_Progress.pdf
- Gollwitzer & Sheeran 2006 — https://www.researchgate.net/publication/37367696_Implementation_Intentions_and_Goal_Achievement_A_Meta-Analysis_of_Effects_and_Processes
- Silverman & Barasch 2023, broken streaks — https://academic.oup.com/jcr/article-abstract/49/6/1095/6623414
- Lally et al. 2010 — https://onlinelibrary.wiley.com/doi/abs/10.1002/ejsp.674
- Dai, Milkman & Riis 2014, fresh start — https://pubsonline.informs.org/doi/10.1287/mnsc.2014.1901
- Amabile & Kramer 2011 — https://hbr.org/2011/05/the-power-of-small-wins
- Weiser & Brown 1995 — https://people.csail.mit.edu/rudolph/Teaching/weiser.pdf
- Amber Case, principios de tecnología calma — https://caseorganic.com/post/principles-of-calm-technology/
- Hick's Law — https://lawsofux.com/hicks-law/ · Fitts's Law — https://lawsofux.com/fittss-law/
- W3C COGA — https://www.w3.org/TR/2018/WD-coga-usable-20181211
- Elliot 2015, color — https://www.frontiersin.org/journals/psychology/articles/10.3389/fpsyg.2015.00368/pdf
- Target size — https://tetralogical.com/blog/2022/12/20/foundations-target-size/
- WCAG 2.3.3 — https://www.w3.org/WAI/WCAG22/Understanding/animation-from-interactions.html
- Crítica al modelo Hook — https://yukaichou.com/gamification-analysis/hook-model-octalysis-habit-addiction/
