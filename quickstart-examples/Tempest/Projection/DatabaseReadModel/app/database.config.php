<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

use Tempest\Database\Config\PostgresConfig;

return new PostgresConfig(
    host: 'database',
    port: '5432',
    username: 'ecotone',
    password: 'secret',
    database: 'ecotone',
);
