<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Throwable;

/**
 * licence Apache-2.0
 */
final class MissingTableFailure
{
    public static function explained(Throwable $failure, DbalTableManager $tableManager, CachedConnectionFactory $connectionFactory): Throwable
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof TableNotFoundException) {
                /** @var DbalContext $context */
                $context = $connectionFactory->createContext();

                return ConfigurationException::create($tableManager->getMissingTableInstructions($context->getDbalConnection()));
            }
        }

        return $failure;
    }
}
