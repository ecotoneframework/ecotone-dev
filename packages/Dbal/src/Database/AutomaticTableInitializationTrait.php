<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;

/**
 * licence Apache-2.0
 */
trait AutomaticTableInitializationTrait
{
    public function shouldBeInitializedAutomatically(Connection $connection): bool
    {
        return $this->shouldAutoInitialize && AutomaticTableInitializationSupport::isSupported($connection);
    }
}
