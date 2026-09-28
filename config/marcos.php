<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Silent focus cue (now-focus, design D7)
    |--------------------------------------------------------------------------
    |
    | Every `focus_cue_minutes` of focus on the active task, the Now card shows
    | a silent inline cue (no sound, modal, notification or focus change). It
    | goes away on its own after `focus_cue_visible_seconds`.
    |
    */

    'focus_cue_minutes' => (int) env('MARCOS_FOCUS_CUE_MINUTES', 25),

    'focus_cue_visible_seconds' => (int) env('MARCOS_FOCUS_CUE_VISIBLE_SECONDS', 60),

];
