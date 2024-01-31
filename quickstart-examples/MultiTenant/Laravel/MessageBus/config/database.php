<?php

return [
    'default' => 'tenant_a_connection',
    'connections' => [
        'tenant_a_connection' => [
            'url' => getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'
            // ... other configuration options
        ],

        'tenant_b_connection' => [
            'url' => getenv('SECONDARY_DATABASE_DSN') ? getenv('SECONDARY_DATABASE_DSN') : 'mysql://ecotone:secret@localhost:3306/ecotone'
            // ... other configuration options
        ],
    ]
];