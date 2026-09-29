<?php

namespace App\Http\Controllers;

use App\Actions\Retirement\RestoreElement;
use App\Actions\Support\Actor;
use App\Http\Resources\RetiredView;
use App\Models\Habit;
use App\Models\Item;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Retirement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Retired view (screen 22): a reflective archive to notice patterns —
 * what was set aside, with the reason written at the time — and the only
 * way back ("Devolver al mapa"). Never a failure list.
 */
class RetiredController extends Controller
{
    public function index(Request $request, RetiredView $view): Response
    {
        return Inertia::render('retired/index', [
            'view' => $view->build($request->user(), $request->only(['kind', 'objective', 'month'])),
        ]);
    }

    public function restore(Request $request, Retirement $retirement, RestoreElement $restore): RedirectResponse
    {
        $element = $restore(Actor::ownerWeb($request->user()), $retirement);

        [$where, $url] = $this->whereItWent($element);

        Inertia::flash('retirement', [
            'message' => $retirement->kind->participle('Devuelt')." al mapa. Está otra vez en {$where}.",
            'url' => $url,
        ]);

        return redirect()->route('retired.index');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function whereItWent(Model $element): array
    {
        return match (true) {
            $element instanceof Item => [$element->objective->title, route('objectives.items.show', [$element->objective->key, $element->key], absolute: false)],
            $element instanceof Plan => [$element->objective->title, route('objectives.plans.show', [$element->objective->key, $element->id], absolute: false)],
            $element instanceof Objective => ['Objetivos', route('objectives.show', $element->key, absolute: false)],
            $element instanceof Habit => ['tus hábitos', route('habits.show', $element->id, absolute: false)],
            default => ['el mapa', route('retired.index', absolute: false)],
        };
    }
}
