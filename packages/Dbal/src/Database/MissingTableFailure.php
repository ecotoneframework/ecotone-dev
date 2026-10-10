<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\Messaging\Config\ConfigurationException;
use Throwable;

/**
 * licence Apache-2.0
 */
final class MissingTableFailure
{
    public static function explained(Throwable $failure, DbalTableManager $tableManager): Throwable
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof TableNotFoundException) {
                return ConfigurationException::create($tableManager->getMissingTableInstructions());
            }
        }

        return $failure;
    }
}
