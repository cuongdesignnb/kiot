<?php

return [
    'offsets' => [
        'write_mode' => env('DEBT_OFFSET_WRITE_MODE', 'legacy'),
        'require_distinct_approver' => env('DEBT_OFFSET_REQUIRE_DISTINCT_APPROVER', true),
        'require_distinct_applier' => env('DEBT_OFFSET_REQUIRE_DISTINCT_APPLIER', false),
    ],
];
