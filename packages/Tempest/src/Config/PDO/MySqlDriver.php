<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config\PDO;

use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Ecotone\Tempest\Config\PDO\Concerns\ConnectsThroughTempestConnection;

/**
 * licence Apache-2.0
 */
final class MySqlDriver extends AbstractMySQLDriver
{
    use ConnectsThroughTempestConnection;
}
