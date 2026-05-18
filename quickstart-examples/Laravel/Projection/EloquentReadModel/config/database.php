<?php

return [
    'default' => 'pgsql',
    'connections' => [
        'pgsql' => [
            'url' => getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone',
        ],
    ],
];
