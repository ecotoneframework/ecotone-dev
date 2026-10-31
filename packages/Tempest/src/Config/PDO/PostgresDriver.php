<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config\PDO;

use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Ecotone\Tempest\Config\PDO\Concerns\ConnectsThroughTempestConnection;

/**
 * licence Apache-2.0
 */
final class PostgresDriver extends AbstractPostgreSQLDriver
{
    use ConnectsThroughTempestConnection;
}
