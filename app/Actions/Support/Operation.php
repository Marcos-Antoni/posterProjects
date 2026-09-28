<?php

namespace App\Actions\Support;

/**
 * Every domain mutation, classified into an AI tier. The minor list is the
 * closed one of the ai-operations spec; anything not listed is major.
 */
enum Operation: string
{
    case CreateObjective = 'create-objective';
    case UpdateObjective = 'update-objective';
    case ActivateObjective = 'activate-objective';
    case ReopenObjective = 'reopen-objective';
    case CreatePlan = 'create-plan';
    case UpdatePlan = 'update-plan';
    case ActivatePlan = 'activate-plan';
    case ReorderPlan = 'reorder-plan';
    case EditControlMap = 'edit-control-map';
    case ConvertControlMapEntry = 'convert-control-map-entry';
    case UpdateMetricCurrent = 'update-metric-current';
    case AddItem = 'add-item';
    case UpdateItem = 'update-item';
    case ReorderItem = 'reorder-item';
    case CheckItem = 'check-item';
    case UncheckItem = 'uncheck-item';
    case AddDependency = 'add-dependency';
    case RemoveDependency = 'remove-dependency';
    // --- phase 3: now and execution ---
    case StartItem = 'start-item';
    case StopItem = 'stop-item';
    case ShrinkStep = 'shrink-step';
    case DismissItemForToday = 'dismiss-item-for-today';
    // --- end phase 3 ---

    public function tier(): AiTier
    {
        return match ($this) {
            self::CheckItem,
            self::UncheckItem,
            self::UpdateMetricCurrent,
            self::ShrinkStep => AiTier::Minor,
            default => AiTier::Major,
        };
    }
}
