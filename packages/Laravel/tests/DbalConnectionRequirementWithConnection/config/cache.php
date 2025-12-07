<?php

return [
    'default' => env('CACHE_DRIVER', 'array'),
    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
    ],
    'prefix' => 'dbal_test_cache_',
];

