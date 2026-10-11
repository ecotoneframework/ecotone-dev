<?php

return [
    'default' => env('CACHE_DRIVER', 'array'),
    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
    ],
    'prefix' => 'dcb_smoke_cache_',
];
