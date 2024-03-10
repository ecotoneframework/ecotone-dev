<?php

return [
    'default' => 'db_connection',
    'connections' => [
        'db_connection' => [
            'url' => getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'
        ],
    ]
];