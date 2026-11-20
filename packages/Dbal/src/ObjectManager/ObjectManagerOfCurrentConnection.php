<?php

declare(strict_types=1);

namespace Ecotone\Dbal\ObjectManager;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;
use Ecotone\Api\Dbal\DbalConnection;
use Ecotone\Api\Dbal\ManagerRegistryProvider;
use Ecotone\Dbal\Configuration\DbalConnectionModule;
use Ecotone\Dbal\EcotoneManagerRegistryConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class ObjectManagerOfCurrentConnection
{
    public const REFERENCE_NAME = 'ecotone.dbal.object_manager_of_current_connection';

    public function __construct(private string $referenceNameOfTheConnectionMessagesAreHandledOn)
    {
    }

    public function of(object $connectionFactory, string $connectionReferenceName): ObjectManager
    {
        if ($connectionReferenceName === DbalConnectionModule::CONNECTION_MESSAGES_ARE_HANDLED_ON) {
            $connectionReferenceName = $this->referenceNameOfTheConnectionMessagesAreHandledOn;
        }
        if ($connectionFactory instanceof MultiTenantConnectionFactory) {
            return $connectionFactory->getManager();
        }
        if ($connectionFactory instanceof EcotoneManagerRegistryConnectionFactory) {
            return $connectionFactory->getManagerOfConnectionRegisteredAs($connectionReferenceName);
        }

        throw ConfigurationException::create(sprintf(
            '#[CurrentConnectionObjectManager] cannot inject the Doctrine ObjectManager of connection `%s`: it is registered as %s, which has no Doctrine ORM behind it. '
            . 'Register the connection with Doctrine ORM: SymfonyConnectionReference::defaultManagerRegistry($connectionName) or SymfonyConnectionReference::createForManagerRegistry($connectionName, referenceName: $referenceName) in a Symfony application, or a service created with %s::createForManagerRegistry($managerRegistry, $connectionName). To give every coroutine an ObjectManager of its own, register a service created with %s::fromDsnAndConfig($dsn, $pathsToMapping). '
            . 'To run SQL on the connection of the handled message without Doctrine ORM, take #[CurrentConnection] %s instead.',
            $connectionReferenceName,
            $connectionFactory::class,
            DbalConnection::class,
            ManagerRegistryProvider::class,
            Connection::class,
        ));
    }
}
