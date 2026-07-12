<?php
declare(strict_types=1);

return [
    // 'off' => every gate is permissive (ship dark). 'enforce' => real resolution.
    'mode' => env('ENTITLEMENTS_MODE', 'off'),

    // Tier names in ascending order — index into each entitlement's value list.
    'tiers' => ['basic', 'pro'],

    // key => ['bool'|'int', [tier0value, tier1value, ...]]
    'entitlements' => [
        'export_data'  => ['bool', [false, true]],
        'projects_max' => ['int',  [3, -1]], // -1 == unlimited
    ],
];
