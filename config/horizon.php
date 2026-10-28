<?php

use App\Http\Middleware\ForceAdminLocale;

return [
    'path' => 'admin/horizon',
    'middleware' => ['web', ForceAdminLocale::class, 'throttle:admin'],
    'waits' => [
        'redis:high' => 15,
        'redis:default' => 60,
        'redis:ocr' => 120,
        'redis:ai' => 120,
        'redis:low' => 300,
    ],
    'defaults' => [
        'high' => [
            'connection' => 'redis', 'queue' => ['high'], 'balance' => false,
            'maxProcesses' => 1, 'memory' => 128, 'tries' => 3, 'timeout' => 60,
        ],
        'default' => [
            'connection' => 'redis', 'queue' => ['default'], 'balance' => false,
            'maxProcesses' => 1, 'memory' => 256, 'tries' => 3, 'timeout' => 200,
        ],
        'ocr' => [
            'connection' => 'redis', 'queue' => ['ocr'], 'balance' => false,
            'maxProcesses' => 1, 'memory' => 512, 'tries' => 3, 'timeout' => 300,
        ],
        'ai' => [
            'connection' => 'redis', 'queue' => ['ai'], 'balance' => false,
            'maxProcesses' => 1, 'memory' => 256, 'tries' => 3, 'timeout' => 420,
        ],
        'low' => [
            'connection' => 'redis', 'queue' => ['low'], 'balance' => false,
            'maxProcesses' => 1, 'memory' => 128, 'tries' => 3, 'timeout' => 60,
        ],
    ],
    'environments' => [
        'local' => array_fill_keys(['high', 'default', 'ocr', 'ai', 'low'], []),
        'production' => array_fill_keys(['high', 'default', 'ocr', 'ai', 'low'], []),
    ],
];
