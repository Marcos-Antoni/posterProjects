<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Bare `{"data": [...]}`, no `meta` — an absent `meta` means the collection
 * is already complete (mirrors `LabelCollectionResponse`, `SprintCollectionResponse`).
 * Items are pre-wrapped `HabitTodayResource::forHabit()` instances by the
 * time they reach this collection, so no re-wrapping happens here.
 */
class HabitTodayCollection extends ResourceCollection
{
    public $collects = HabitTodayResource::class;
}
