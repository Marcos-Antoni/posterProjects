<?php

namespace App\Http\Resources;

use App\Actions\Retirement\RetirementHandlers;
use App\Enums\ItemKind;
use App\Enums\RetirableKind;
use App\Enums\RetirementDecision;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Retirement;
use App\Models\Scopes\NotRetired;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The Retired view read model (retirement spec "The Retired View Surfaces
 * Patterns", screen 22), shared by the web page and MCP `retired-view`:
 * every open retirement with kind, title, objective, reason, content
 * decision, age at retirement and date; filters by kind, objective and
 * month; counts per reason keyword and the median age per kind. Figures are
 * observations for learning, never a score or a rate.
 *
 * @phpstan-type RestoreInfo array{allowed: bool, blocked_reason: string|null, summary: list<string>}
 * @phpstan-type Entry array{id: int, kind: string, kind_label: string, title: string, objective_key: string|null, objective_label: string, reason: string, decision: string, decision_text: string, is_root: bool, includes: int, age_days: int, retired_at: string, retired_on: string, month: string, restore: RestoreInfo}
 * @phpstan-type Filters array{kind: string|null, objective: string|null, month: string|null}
 */
final class RetiredView
{
    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private const MONTHS_SHORT = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /**
     * Words that never make a reason keyword on their own.
     */
    private const STOP_WORDS = ['porque', 'para', 'cuando', 'quizás', 'todavía', 'ahora', 'nunca', 'siempre', 'mejor', 'desde', 'hasta', 'sobre', 'entre'];

    /**
     * Days of life under which a task counts as retired "early".
     */
    private const EARLY_DAYS = 3;

    public function __construct(private RetirementHandlers $handlers) {}

    /**
     * @param  array<string, mixed>  $input  raw `kind`, `objective` (key) and `month` (YYYY-MM) filters
     * @return array<string, mixed> total, filters, kinds, objectives, month_options, months (with entries), patterns, used_reasons
     */
    public function build(User $owner, array $input = []): array
    {
        $retirements = Retirement::query()
            ->where('user_id', $owner->id)
            ->open()
            ->with('objective:id,key,title')
            ->orderByDesc('retired_at')
            ->orderByDesc('id')
            ->get();

        $all = $this->entries($retirements);

        $objectives = $retirements->pluck('objective')->filter()->unique('id')
            ->sortBy('title')
            ->map(fn (Objective $objective): array => ['key' => $objective->key, 'title' => $objective->title])
            ->values();

        $filters = $this->filters($input, $objectives);

        $withoutKind = array_values(array_filter($all, fn (array $entry): bool => ($filters['objective'] === null || $entry['objective_key'] === $filters['objective'])
            && ($filters['month'] === null || $entry['month'] === $filters['month'])));

        $filtered = array_values(array_filter($withoutKind, fn (array $entry): bool => $filters['kind'] === null || $entry['kind'] === $filters['kind']));

        $months = [];

        foreach ($filtered as $entry) {
            $months[$entry['month']][] = $entry;
        }

        return [
            'total' => count($filtered),
            'filters' => $filters,
            'kinds' => [
                ['value' => 'all', 'label' => 'Todo', 'count' => count($withoutKind)],
                ...array_map(fn (RetirableKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->pluralLabel(),
                    'count' => count(array_filter($withoutKind, fn (array $entry): bool => $entry['kind'] === $kind->value)),
                ], RetirableKind::cases()),
            ],
            'objectives' => $objectives->all(),
            'month_options' => array_map(fn (string $month): array => [
                'value' => $month,
                'label' => $this->monthLabel($month),
            ], array_values(array_unique(array_column($all, 'month')))),
            'months' => array_map(fn (string $month, array $entries): array => [
                'value' => $month,
                'label' => $this->monthLabel($month),
                'count' => count($entries),
                'entries' => $entries,
            ], array_map('strval', array_keys($months)), array_values($months)),
            'patterns' => $this->patterns($filtered),
            'used_reasons' => $this->usedReasons($owner),
        ];
    }

    /**
     * The reasons the owner already wrote, as one-click shortcuts for the
     * retire dialog: repeated keywords first, then other reasons, at most 3.
     *
     * @return list<string>
     */
    public function usedReasons(User $owner): array
    {
        $reasons = array_map('strval', array_values(Retirement::query()
            ->where('user_id', $owner->id)
            ->whereNull('parent_id')
            ->orderByDesc('retired_at')
            ->limit(200)
            ->pluck('reason')
            ->all()));

        [$keywords, $unassigned] = $this->keywords($this->heads($reasons));

        $rest = array_count_values($unassigned);
        arsort($rest);

        return array_slice(array_values(array_unique([...array_column($keywords, 'keyword'), ...array_map('strval', array_keys($rest))])), 0, 3);
    }

    /**
     * @param  EloquentCollection<int, Retirement>  $retirements
     * @return list<Entry>
     */
    private function entries(EloquentCollection $retirements): array
    {
        $elements = $this->elements($retirements);
        $openPrerequisite = $this->itemsWithOpenPrerequisite($retirements, $elements);
        $openChildren = Retirement::query()
            ->whereIn('parent_id', $retirements->pluck('id'))
            ->open()
            ->selectRaw('parent_id, count(*) as total')
            ->groupBy('parent_id')
            ->pluck('total', 'parent_id');
        $parents = $retirements->keyBy('id');

        $entries = [];

        foreach ($retirements as $retirement) {
            $element = $elements->get($retirement->retirable_type.':'.$retirement->retirable_id);

            if ($element === null) {
                continue;
            }

            $handler = $this->handlers->for($element);
            $blocker = $handler->restoreBlocker($element);
            $retiredOn = $this->localDate($retirement->retired_at);

            $entries[] = [
                'id' => $retirement->id,
                'kind' => $retirement->kind->value,
                'kind_label' => $retirement->kind->label(),
                'title' => $handler->title($element),
                'objective_key' => $retirement->objective?->key,
                'objective_label' => $retirement->objective->title ?? ($retirement->kind === RetirableKind::Capture ? 'Inbox' : 'Sin objetivo'),
                'reason' => $retirement->reason,
                'decision' => $retirement->decision->value,
                'decision_text' => $this->decisionText($retirement, $parents->get((int) $retirement->parent_id)),
                'is_root' => $retirement->parent_id === null,
                'includes' => (int) $openChildren->get($retirement->id, 0),
                'age_days' => $this->ageDays($element, $retirement),
                'retired_at' => $retirement->retired_at->toIso8601String(),
                'retired_on' => $retiredOn->toDateString(),
                'month' => $retiredOn->format('Y-m'),
                'restore' => [
                    'allowed' => $blocker === null,
                    'blocked_reason' => $blocker,
                    'summary' => $blocker === null
                        ? $this->restoreSummary($retirement, $element, $openPrerequisite, (int) $openChildren->get($retirement->id, 0), $retiredOn)
                        : [],
                ],
            ];
        }

        return $entries;
    }

    /**
     * Every retired element, loaded past the NotRetired scope, one query per
     * kind, keyed "type:id".
     *
     * @param  EloquentCollection<int, Retirement>  $retirements
     * @return Collection<string, Model>
     */
    private function elements(EloquentCollection $retirements): Collection
    {
        $elements = new Collection;

        foreach ($retirements->groupBy('retirable_type') as $type => $rows) {
            $class = Model::getActualClassNameForMorph((string) $type);

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $query = $class::query()->withoutGlobalScope(NotRetired::class)->whereKey($rows->pluck('retirable_id'));

            if ($class === Item::class) {
                $query->with(['plan', 'objective']);
            }

            if ($class === Plan::class) {
                $query->with('objective');
            }

            foreach ($query->get() as $element) {
                $elements->put($type.':'.$element->getKey(), $element);
            }
        }

        return $elements;
    }

    /**
     * Ids of retired items that would come back locked (a non-retired
     * prerequisite still open), in one query.
     *
     * @param  EloquentCollection<int, Retirement>  $retirements
     * @param  Collection<string, Model>  $elements
     * @return array<int, bool>
     */
    private function itemsWithOpenPrerequisite(EloquentCollection $retirements, Collection $elements): array
    {
        $itemIds = $retirements->where('retirable_type', 'item')->pluck('retirable_id');

        if ($itemIds->isEmpty()) {
            return [];
        }

        return DB::table('item_dependencies')
            ->join('items as prerequisites', 'prerequisites.id', '=', 'item_dependencies.prerequisite_id')
            ->whereIn('item_dependencies.dependent_id', $itemIds)
            ->whereNull('prerequisites.retired_at')
            ->whereNull('prerequisites.completed_at')
            ->pluck('item_dependencies.dependent_id')
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();
    }

    /**
     * "What happened to its content", in the mockup's words.
     */
    private function decisionText(Retirement $retirement, ?Retirement $parent): string
    {
        $kind = $retirement->kind;
        $payload = $retirement->decision_payload ?? [];

        if ($retirement->parent_id !== null) {
            $parentKind = $parent !== null ? $parent->kind : Retirement::query()->whereKey($retirement->parent_id)->value('kind');
            $parentKind = $parentKind instanceof RetirableKind ? $parentKind : RetirableKind::tryFrom((string) $parentKind);

            return $kind->participle('Retirad').' junto con su '.mb_strtolower($parentKind?->label() ?? 'plan');
        }

        return match ($retirement->decision) {
            RetirementDecision::ArchiveAsIs => $kind->participle('Archivad').' tal cual'.$this->archivedContent($kind, $payload),
            RetirementDecision::Split => $kind->participle('Dividid').' en '.$this->quotedList(array_column($payload['created'] ?? [], 'title')),
            RetirementDecision::Move => $this->movedText($kind, $payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function archivedContent(RetirableKind $kind, array $payload): string
    {
        return match (true) {
            $kind === RetirableKind::Plan && ($payload['items'] ?? 0) > 0 => ', con '.$this->count((int) $payload['items'], 'su tarea', 'sus %d tareas'),
            $kind === RetirableKind::Objective && ($payload['plans'] ?? 0) > 0 => ', con '.$this->count((int) $payload['plans'], 'su plan', 'sus %d planes'),
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function movedText(RetirableKind $kind, array $payload): string
    {
        $target = (string) ($payload['target']['title'] ?? '');

        return match ($kind) {
            RetirableKind::Plan => 'Movido: '.$this->count((int) ($payload['moved'] ?? 0), 'su tarea pasó', 'sus %d tareas pasaron')." a “{$target}”",
            RetirableKind::Objective => 'Movido: '.$this->count((int) ($payload['moved'] ?? 0), 'su plan pasó', 'sus %d planes pasaron')." a “{$target}”",
            default => $kind->participle('Movid').": lo que abría ahora cuelga de “{$target}”",
        };
    }

    /**
     * The lines of the "Devolver al mapa" confirmation.
     *
     * @param  array<int, bool>  $openPrerequisite
     * @return list<string>
     */
    private function restoreSummary(Retirement $retirement, Model $element, array $openPrerequisite, int $openChildren, CarbonInterface $retiredOn): array
    {
        $payload = $retirement->decision_payload ?? [];
        $lines = [];

        $lines[] = match (true) {
            $element instanceof Item => "Vuelve a {$element->objective->title} como estaba antes: {$this->itemStateWord($element, isset($openPrerequisite[$element->id]))}.",
            $element instanceof Plan => "Vuelve a {$element->objective->title} como estaba antes.",
            $element instanceof Objective => 'Vuelve a Objetivos y a la navegación como estaba antes.',
            $element instanceof Habit => 'Vuelve a tus hábitos y acepta registros otra vez. Su historial no cambió.',
            default => 'Vuelve al mapa como estaba antes.',
        };

        if ($retirement->decision === RetirementDecision::Split) {
            $parts = count($payload['created'] ?? []);
            $lines[] = $retirement->kind === RetirableKind::Plan
                ? "Los {$parts} planes en que lo dividiste siguen en el mapa. Si sobran, los retirás después."
                : "Las {$parts} partes en que la dividiste siguen en el mapa. Si sobran, las retirás después.";
        }

        if ($retirement->decision === RetirementDecision::Move) {
            $lines[] = 'Lo que moviste sigue en “'.($payload['target']['title'] ?? '').'”.';
        }

        if ($openChildren > 0) {
            $lines[] = 'Vuelve con '.$this->count($openChildren, 'lo que se retiró junto', 'los %d elementos que se retiraron junto').' con '.($retirement->kind->isFeminine() ? 'ella.' : 'él.');
        }

        $lines[] = 'El retiro del '.$retiredOn->day.' '.self::MONTHS_SHORT[$retiredOn->month - 1].' queda en su historial, con su razón.';

        return $lines;
    }

    private function itemStateWord(Item $item, bool $locked): string
    {
        $feminine = $item->kind === ItemKind::Task;

        return match (true) {
            $item->completed_at !== null => $feminine ? 'hecha' : 'hecho',
            $locked => $feminine ? 'bloqueada' : 'bloqueado',
            default => 'disponible',
        };
    }

    /**
     * Whole UTC-6 days between creating the element and retiring it.
     */
    private function ageDays(Model $element, Retirement $retirement): int
    {
        $created = $element->getAttribute('created_at');

        if (! $created instanceof CarbonInterface) {
            return 0;
        }

        return max(0, (int) $this->localDate($created)->diffInDays($this->localDate($retirement->retired_at)));
    }

    /**
     * @param  list<Entry>  $entries
     * @return array{total: int, observation: string|null, question: string|null, reasons: list<array{keyword: string, count: int}>, other_reasons: int, median_ages: list<array{kind: string, label: string, count: int, days: int}>}
     */
    private function patterns(array $entries): array
    {
        // Reasons count what the owner did: only root retirements; what was
        // retired along with a plan or objective shares its reason.
        $roots = array_values(array_filter($entries, fn (array $entry): bool => $entry['is_root']));

        [$keywords, $unassigned] = $this->keywords($this->heads(array_column($roots, 'reason')));

        $medians = [];

        foreach (RetirableKind::cases() as $kind) {
            $ages = array_column(array_filter($entries, fn (array $entry): bool => $entry['kind'] === $kind->value), 'age_days');

            if ($ages !== []) {
                $medians[] = ['kind' => $kind->value, 'label' => $kind->pluralLabel(), 'count' => count($ages), 'days' => (int) round((float) collect($ages)->median())];
            }
        }

        $tasks = count(array_filter($entries, fn (array $entry): bool => $entry['kind'] === RetirableKind::Task->value));
        $early = count(array_filter($entries, fn (array $entry): bool => $entry['kind'] === RetirableKind::Task->value && $entry['age_days'] <= self::EARLY_DAYS));
        $sentences = [];
        $question = null;

        if ($tasks >= 2 && $early >= 2) {
            $sentences[] = "{$early} de {$tasks} tareas se retiraron en sus primeros ".self::EARLY_DAYS.' días.';
            $question = '¿qué tenían en común esas tareas cuando las creaste?';
        }

        if ($keywords !== []) {
            $top = array_slice(array_column($keywords, 'keyword'), 0, 2);
            $sentences[] = count($top) === 1
                ? "La razón que más volvés a escribir es “{$top[0]}”."
                : "Las razones que más volvés a escribir son “{$top[0]}” y “{$top[1]}”.";
            $question ??= '¿qué se repite en esas razones cuando las escribís?';
        }

        return [
            'total' => count($roots),
            'observation' => $sentences === [] ? null : implode(' ', $sentences),
            'question' => $question,
            'reasons' => $keywords,
            'other_reasons' => count($unassigned),
            'median_ages' => $medians,
        ];
    }

    /**
     * Keywords repeated across reasons, as the owner wrote them: the longest
     * word-prefix of a reason's first clause shared by at least two reasons,
     * chosen greedily (most reasons first). Returns the keywords with their
     * counts and the reasons that matched none.
     *
     * @param  list<string>  $heads
     * @return array{0: list<array{keyword: string, count: int}>, 1: list<string>}
     */
    private function keywords(array $heads): array
    {
        $remaining = $heads;
        $keywords = [];

        while (count($remaining) >= 2) {
            $candidates = [];

            foreach (array_unique($remaining) as $head) {
                $words = explode(' ', $head);

                for ($length = 1; $length <= count($words); $length++) {
                    $candidate = implode(' ', array_slice($words, 0, $length));

                    if ($length === 1 && (mb_strlen($candidate) < 5 || in_array($candidate, self::STOP_WORDS, true))) {
                        continue;
                    }

                    $candidates[$candidate] = $length;
                }
            }

            $best = null;
            $bestLength = 0;
            $bestCount = 1;

            foreach ($candidates as $candidate => $length) {
                $count = count(array_filter($remaining, fn (string $head): bool => $this->startsWithWords($head, (string) $candidate)));

                if ($count > $bestCount || ($count === $bestCount && $best !== null && $length > $bestLength)) {
                    $best = (string) $candidate;
                    $bestLength = $length;
                    $bestCount = $count;
                }
            }

            if ($best === null) {
                break;
            }

            $keywords[] = ['keyword' => $best, 'count' => $bestCount];
            $remaining = array_values(array_filter($remaining, fn (string $head): bool => ! $this->startsWithWords($head, $best)));
        }

        usort($keywords, fn (array $a, array $b): int => [$b['count'], $a['keyword']] <=> [$a['count'], $b['keyword']]);

        return [$keywords, $remaining];
    }

    /**
     * The non-empty first clauses of the given reasons.
     *
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private function heads(array $reasons): array
    {
        return array_values(array_filter(array_map(fn (string $reason): string => $this->head($reason), $reasons), fn (string $head): bool => $head !== ''));
    }

    private function startsWithWords(string $head, string $prefix): bool
    {
        return $head === $prefix || str_starts_with($head, $prefix.' ');
    }

    /**
     * A reason's first clause, lowercased, single-spaced.
     */
    private function head(string $reason): string
    {
        $first = preg_split('/[,;:.()—–]|\s-\s/u', mb_strtolower($reason))[0] ?? '';

        return trim((string) preg_replace('/\s+/u', ' ', $first));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  Collection<int, array{key: string, title: string}>  $objectives
     * @return Filters
     */
    private function filters(array $input, Collection $objectives): array
    {
        $kind = is_string($input['kind'] ?? null) ? RetirableKind::tryFrom($input['kind'])?->value : null;
        $objective = is_string($input['objective'] ?? null) && $objectives->contains('key', $input['objective']) ? $input['objective'] : null;
        $month = is_string($input['month'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $input['month']) === 1 ? $input['month'] : null;

        return ['kind' => $kind, 'objective' => $objective, 'month' => $month];
    }

    private function monthLabel(string $month): string
    {
        [$year, $number] = explode('-', $month);

        return ucfirst(self::MONTHS[(int) $number - 1]).' '.$year;
    }

    /**
     * A moment's calendar day in the fixed UTC-6 zone.
     */
    private function localDate(CarbonInterface $moment): Carbon
    {
        return Carbon::parse($moment->clone()->setTimezone(Config::string('habits.timezone'))->toDateString());
    }

    /**
     * @param  list<string>  $titles
     */
    private function quotedList(array $titles): string
    {
        $quoted = array_map(fn (string $title): string => "“{$title}”", $titles);
        $last = array_pop($quoted);

        return $quoted === [] ? (string) $last : implode(', ', $quoted).' y '.$last;
    }

    private function count(int $count, string $one, string $many): string
    {
        return $count === 1 ? $one : sprintf($many, $count);
    }
}
