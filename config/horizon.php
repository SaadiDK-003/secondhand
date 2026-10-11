<?php

use App\Http\Middleware\EnsureActiveUser;

return [
    'name' => 'Second Hand',
    'domain' => null,
    'path' => 'horizon',
    'use' => 'default',
    'prefix' => 'secondhand_horizon:',
    'middleware' => ['web', 'auth', EnsureActiveUser::class],
    'waits' => ['redis:default' => 60],
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'silenced' => [],
    'silenced_tags' => [],
    'metrics' => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    'fast_termination' => false,
    'memory_limit' => 64,
    'defaults' => [
        'marketplace' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'simple',
            'maxProcesses' => 2,
            'maxTime' => 3600,
            'maxJobs' => 500,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],
    'environments' => [
        'production' => ['marketplace' => []],
        'local' => ['marketplace' => ['maxProcesses' => 1]],
    ],
];
