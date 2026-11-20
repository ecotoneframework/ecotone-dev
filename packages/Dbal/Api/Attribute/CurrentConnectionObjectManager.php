<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Attribute;

use Attribute;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Dbal\Configuration\DbalConnectionModule;
use Ecotone\Dbal\ObjectManager\ObjectManagerOfCurrentConnection;

#[Attribute(Attribute::TARGET_PARAMETER)]
/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class CurrentConnectionObjectManager extends Reference
{
    public function __construct(?string $connectionReferenceName = null)
    {
        $connectionReferenceName ??= DbalConnectionModule::CONNECTION_MESSAGES_ARE_HANDLED_ON;
        parent::__construct(
            $connectionReferenceName,
            sprintf("reference('%s').of(service, '%s')", ObjectManagerOfCurrentConnection::REFERENCE_NAME, addslashes($connectionReferenceName)),
        );
    }
}
