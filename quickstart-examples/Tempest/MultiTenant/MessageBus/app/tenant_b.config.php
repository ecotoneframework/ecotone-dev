<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

use Tempest\Database\Config\MysqlConfig;

return new MysqlConfig(
    host: 'database-mysql',
    port: '3306',
    username: 'ecotone',
    password: 'secret',
    database: 'ecotone',
    tag: 'tenant_b',
);
