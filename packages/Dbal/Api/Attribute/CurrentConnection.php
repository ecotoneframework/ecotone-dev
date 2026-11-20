<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Attribute;

use Attribute;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Dbal\Configuration\DbalConnectionModule;

#[Attribute(Attribute::TARGET_PARAMETER)]
/**
 * licence Apache-2.0
 */
final class CurrentConnection extends Reference
{
    public function __construct(?string $connectionReferenceName = null)
    {
        $connectionReferenceName ??= DbalConnectionModule::CONNECTION_MESSAGES_ARE_HANDLED_ON;
        parent::__construct($connectionReferenceName, 'service.createContext().getDbalConnection()');
    }
}
