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
    // --- phase 4: habits ---
    case CreateHabit = 'create-habit';
    case UpdateHabit = 'update-habit';
    case ChangeHabitLevel = 'change-habit-level';
    case LogHabitEntry = 'log-habit-entry';
    case DecrementHabitEntry = 'decrement-habit-entry';
    case LogHabitTwoMinute = 'log-habit-two-minute';
    case UndoHabitTwoMinute = 'undo-habit-two-minute';
    // --- end phase 4 ---
    // --- phase 6: retirement (major: create, retire or restore any element) ---
    case RetireElement = 'retire-element';
    case RestoreElement = 'restore-element';
    // --- end phase 6 ---
    // --- phase 7: capture inbox and reviews ---
    case CreateCapture = 'create-capture';
    case ConvertCaptureToItem = 'convert-capture-to-item';
    case ConvertCaptureToHabit = 'convert-capture-to-habit';
    case ConvertCaptureToObjectiveDraft = 'convert-capture-to-objective-draft';
    case CreateObjectiveDraft = 'create-objective-draft';
    case SubmitWeeklyReview = 'submit-weekly-review';
    case CloseObjective = 'close-objective';
    // --- end phase 7 ---

    public function tier(): AiTier
    {
        return match ($this) {
            self::CheckItem,
            self::UncheckItem,
            self::UpdateMetricCurrent,
            self::ShrinkStep => AiTier::Minor,
            self::LogHabitEntry, self::LogHabitTwoMinute => AiTier::Minor, // phase 4
            self::CreateCapture => AiTier::Minor, // phase 7: the AI may note something down while it works
            default => AiTier::Major,
        };
    }
}
