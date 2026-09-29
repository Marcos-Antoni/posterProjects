<?php

namespace App\Actions\Retirement;

use App\Enums\RetirableKind;
use App\Enums\RetirementDecision;
use App\Models\Retirement;
use Illuminate\Database\Eloquent\Model;

/**
 * How one kind of element is retired and restored (design D8). The
 * `RetireElement` / `RestoreElement` actions own the envelope (ownership,
 * reason, tier gate, transaction, history rows); a handler owns what is
 * specific to its kind: which content decisions apply, how each one moves,
 * splits or cascades the content, and what blocks a restore.
 *
 * New retirable kinds (Phase 7 captures) plug in by implementing this
 * interface and registering it in `RetirementHandlers`, without changing the
 * actions' signatures.
 *
 * @template TElement of Model
 */
interface RetirementHandler
{
    /**
     * @param  TElement  $element
     */
    public function kind(Model $element): RetirableKind;

    /**
     * The id of the user who owns the element.
     *
     * @param  TElement  $element
     */
    public function ownerId(Model $element): int;

    /**
     * The objective the element hangs from, if any (snapshotted for filters).
     *
     * @param  TElement  $element
     */
    public function objectiveId(Model $element): ?int;

    /**
     * The element's human title.
     *
     * @param  TElement  $element
     */
    public function title(Model $element): string;

    /**
     * The state to record in `prior_state` (what restore goes back to).
     *
     * @param  TElement  $element
     */
    public function priorState(Model $element): string;

    /**
     * The content decisions this element accepts, in the dialog's order.
     *
     * @param  TElement  $element
     * @return list<RetirementDecision>
     */
    public function decisions(Model $element): array;

    /**
     * The decision taken when the owner gives none (archive as-is for an
     * element with no content), or null when the owner must choose.
     *
     * @param  TElement  $element
     */
    public function defaultDecision(Model $element): ?RetirementDecision;

    /**
     * Refuse elements that may not be retired (e.g. inside a closed
     * objective) with a ValidationException.
     *
     * @param  TElement  $element
     */
    public function guard(Model $element): void;

    /**
     * Ids of the items whose dependents may unlock when this element leaves.
     *
     * @param  TElement  $element
     * @return list<int>
     */
    public function itemIds(Model $element): array;

    /**
     * Apply the decision and retire the element (and its cascaded content),
     * writing history through the recorder. Runs inside the transaction; a
     * ValidationException rolls everything back.
     *
     * @param  TElement  $element
     * @param  array<string, mixed>  $payload
     */
    public function retire(Model $element, string $reason, RetirementDecision $decision, array $payload, RetirementRecorder $recorder): RetirementOutcome;

    /**
     * Why the element cannot come back right now (a retired or closed
     * parent), or null when it can.
     *
     * @param  TElement  $element
     */
    public function restoreBlocker(Model $element): ?string;

    /**
     * Bring the element back to its prior non-retired state.
     *
     * @param  TElement  $element
     */
    public function restore(Model $element, Retirement $retirement): void;
}
