<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Config\PDO\Concerns;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Ecotone\Tempest\Config\PDO\Connection;
use Ecotone\Tempest\Config\PDO\LostTempestConnectionExceptionConverter;
use Ecotone\Tempest\Config\PDO\TempestConnectionPdo;

/**
 * licence Apache-2.0
 */
trait ConnectsThroughTempestConnection
{
    public function __construct(private ?string $configTag)
    {
    }

    public function connect(array $params): Connection
    {
        return new Connection(TempestConnectionPdo::connect($this->configTag));
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return new LostTempestConnectionExceptionConverter(parent::getExceptionConverter(), $this->configTag);
    }
}
