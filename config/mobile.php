<?php
declare(strict_types=1);

return [
    'version_gate' => [
        // Master switch. Off by default — the gate is fully inert until enabled.
        'enabled' => (bool) env('VERSION_GATE_ENABLED', false),

        'min_version' => [
            'ios'     => env('VERSION_GATE_MIN_IOS', '0.0.0'),
            'android' => env('VERSION_GATE_MIN_ANDROID', '0.0.0'),
        ],

        'update_url' => [
            'ios'     => env('VERSION_GATE_URL_IOS', ''),
            'android' => env('VERSION_GATE_URL_ANDROID', ''),
        ],
    ],
];
