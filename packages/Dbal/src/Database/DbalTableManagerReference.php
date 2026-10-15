<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;

/**
 * Reference to a DbalTableManager service registered in the container.
 * Feature modules register their DbalTableManager implementations as services
 * and provide this reference so DatabaseSetupModule can collect them.
 *
 * licence Apache-2.0
 */
final class DbalTableManagerReference
{
    public function __construct(
        private string $referenceName,
        private string $connectionReferenceName = DbalConnectionReference::DEFAULT,
    ) {
    }

    public function getReferenceName(): string
    {
        return $this->referenceName;
    }

    public function getConnectionReferenceName(): string
    {
        return $this->connectionReferenceName;
    }
}
