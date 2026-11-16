<?php

$staffAliases = [];
foreach (array_filter(array_map('trim', explode(',', (string) env('REPORT_STAFF_ALIASES', '')))) as $pair) {
    [$source, $target] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, null);
    if (ctype_digit((string) $source) && ctype_digit((string) $target) && $source !== $target) {
        $staffAliases[(int) $source] = (int) $target;
    }
}

return [
    /*
    |--------------------------------------------------------------------------
    | Staff aliases for sales-by-staff reports
    |--------------------------------------------------------------------------
    |
    | Display/report-level only: documents stay untouched. Map a user id whose
    | figures should be shown under another user, e.g. an account that was
    | logged in by mistake on a staff machine. Format: "19:5,20:6"
    | (source_user_id:target_user_id). Empty by default.
    |
    */
    'staff_aliases' => $staffAliases,
];
