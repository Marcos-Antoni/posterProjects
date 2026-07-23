<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Habit Day Boundary Timezone
    |--------------------------------------------------------------------------
    |
    | Entries are stored in UTC, but "which day did this land on" and the
    | planned-vs-actual comparison are computed against this fixed zone.
    | It is a product decision (owner lives on UTC-6, no DST) — it is NOT
    | the application timezone and must never replace `app.timezone`.
    |
    | Note the POSIX sign inversion: "Etc/GMT+6" means UTC-6.
    |
    */

    'timezone' => 'Etc/GMT+6',

];
