<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Configuration;

use Doctrine\DBAL\Connection;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\Attribute\CurrentConnectionObjectManager;
use Ecotone\Api\Dbal\DbalConnection;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\Dbal\ManagerRegistryProvider;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\ObjectManager\ObjectManagerOfCurrentConnection;
use Ecotone\Lite\Test\Configuration\ProvidedReferences;
use Ecotone\Lite\Test\Configuration\ResolvesExtensionObjectsForFlowTesting;
use Ecotone\Messaging\Attribute\EndpointAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\LicensingException;

#[ModuleAnnotation]
/**
 * Central module for registering Dbal connection factory requirement.
 * All Dbal features use the connection factory, so this module validates it's properly configured.
 *
 * licence Apache-2.0
 */
final class DbalConnectionModule implements AnnotationModule, ResolvesExtensionObjectsForFlowTesting
{
    public const CONNECTION_MESSAGES_ARE_HANDLED_ON = 'ecotone.dbal.connection_messages_are_handled_on';

    /**
     * @param array<string, string> $connectionsInjectedIntoParameters
     * @param array<string, string> $objectManagersInjectedIntoParameters
     */
    private function __construct(private array $connectionsInjectedIntoParameters, private array $objectManagersInjectedIntoParameters)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $connectionsInjectedIntoParameters = [];
        $objectManagersInjectedIntoParameters = [];
        foreach ([...$annotationRegistrationService->findAnnotatedMethods(EndpointAnnotation::class), ...$annotationRegistrationService->findAnnotatedMethods(EventHandler::class), ...$annotationRegistrationService->findAnnotatedMethods(ConsoleCommand::class)] as $annotatedMethod) {
            $interfaceToCall = $interfaceToCallRegistry->getFor($annotatedMethod->getClassName(), $annotatedMethod->getMethodName());

            foreach ($interfaceToCall->getInterfaceParameters() as $interfaceParameter) {
                if (! $interfaceParameter->hasAnnotation(CurrentConnection::class) && $interfaceParameter->getTypeDescriptor()->isClassOfType(Connection::class)) {
                    throw ConfigurationException::create(sprintf(
                        '%s::%s($%s) takes a %s without #[CurrentConnection]. '
                        . 'A connection injected as a service is one fixed connection, and the message being handled does not have to run on it: Ecotone gives every execution running concurrently (a coroutine or a fiber) its own connection, so what the handler stores through the injected one would be outside the transaction of the message. '
                        . "Mark the parameter with #[CurrentConnection] (%s) to be given the connection the message is handled on, or with #[CurrentConnection('name')] for a connection registered under another name. A connection Ecotone does not manage is taken through the constructor of the class instead.",
                        $annotatedMethod->getClassName(),
                        $annotatedMethod->getMethodName(),
                        $interfaceParameter->getName(),
                        Connection::class,
                        CurrentConnection::class,
                    ));
                }

                foreach ($interfaceParameter->getAnnotationsOfType(CurrentConnection::class) as $currentConnection) {
                    $placement = sprintf('%s::%s($%s)', $annotatedMethod->getClassName(), $annotatedMethod->getMethodName(), $interfaceParameter->getName());
                    if (! $interfaceParameter->canBePassedIn(Type::object(Connection::class))) {
                        throw ConfigurationException::create(sprintf(
                            '#[CurrentConnection] on %s injects the %s Ecotone opened for the message being handled, but the parameter is typed `%s`. '
                            . 'Type the parameter as %s, or remove #[CurrentConnection] from it.',
                            $placement,
                            Connection::class,
                            $interfaceParameter->getTypeHint(),
                            Connection::class,
                        ));
                    }

                    $connectionsInjectedIntoParameters[$currentConnection->getReferenceName()] ??= $placement;
                }

                foreach ($interfaceParameter->getAnnotationsOfType(CurrentConnectionObjectManager::class) as $currentConnectionObjectManager) {
                    $objectManagersInjectedIntoParameters[$currentConnectionObjectManager->getReferenceName()] ??= sprintf('%s::%s($%s)', $annotatedMethod->getClassName(), $annotatedMethod->getMethodName(), $interfaceParameter->getName());
                }
            }
        }

        return new self($connectionsInjectedIntoParameters, $objectManagersInjectedIntoParameters);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(
            DbalConfiguration::class,
            $extensionObjects,
            DbalConfiguration::createWithDefaults()
        );

        $connectionFactories = $dbalConfiguration->defaultConnectionReferenceNames ?: [DbalConnectionReference::DEFAULT];

        foreach ($connectionFactories as $connectionFactory) {
            $messagingConfiguration->requireReference(
                $connectionFactory,
                sprintf(
                    "Dbal module requires '%s' to be configured. " .
                    "For Symfony, add SymfonyConnectionReference::defaultConnection('default') to your ServiceContext. " .
                    'For Laravel, add LaravelConnectionReference::defaultConnection() to your ServiceContext. ' .
                    'See: https://docs.ecotone.tech/modules/dbal-support#configuration',
                    $connectionFactory
                )
            );
        }

        if ($this->objectManagersInjectedIntoParameters !== [] && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create(sprintf(
                '#[CurrentConnectionObjectManager] on %s requires an Ecotone Enterprise licence. See https://docs.ecotone.tech/enterprise. '
                . 'Without the licence, take #[CurrentConnection] %s to run SQL on the connection of the handled message, or store the entity as an #[Aggregate] through DbalConfiguration::withDoctrineORMRepositories().',
                implode(', ', $this->objectManagersInjectedIntoParameters),
                Connection::class,
            ));
        }
        $multiTenantConfigurations = ExtensionObjectResolver::resolve(MultiTenantConfiguration::class, $extensionObjects);
        $attributeWithoutAConnectionName = match (true) {
            isset($this->connectionsInjectedIntoParameters[self::CONNECTION_MESSAGES_ARE_HANDLED_ON]) => 'CurrentConnection',
            isset($this->objectManagersInjectedIntoParameters[self::CONNECTION_MESSAGES_ARE_HANDLED_ON]) => 'CurrentConnectionObjectManager',
            default => null,
        };
        $placementWithoutAConnectionName = $this->connectionsInjectedIntoParameters[self::CONNECTION_MESSAGES_ARE_HANDLED_ON] ?? $this->objectManagersInjectedIntoParameters[self::CONNECTION_MESSAGES_ARE_HANDLED_ON] ?? '';
        $connectionMessagesAreHandledOn = $multiTenantConfigurations === [] ? $connectionFactories[0] : $multiTenantConfigurations[0]->getReferenceName();
        if ($attributeWithoutAConnectionName !== null && $multiTenantConfigurations !== [] && ! in_array($connectionMessagesAreHandledOn, $connectionFactories, true)) {
            throw ConfigurationException::create(sprintf(
                '#[%s] without a connection name on %s takes the connection of the active tenant, registered as `%s`, but DbalConfiguration opens the transaction of a message on `%s`, so what the handler stores would be outside that transaction. '
                . "Open transactions on the connection of the tenants: DbalConfiguration::createWithDefaults()->withDefaultConnectionReferenceNames(['%s']), or register the MultiTenantConfiguration without a reference name of its own. "
                . "To take another connection, name it: #[%s('%s')].",
                $attributeWithoutAConnectionName,
                $placementWithoutAConnectionName,
                $connectionMessagesAreHandledOn,
                implode('`, `', $connectionFactories),
                $connectionMessagesAreHandledOn,
                $attributeWithoutAConnectionName,
                $connectionFactories[0],
            ));
        }
        if ($attributeWithoutAConnectionName !== null && $multiTenantConfigurations === [] && count($connectionFactories) > 1) {
            throw ConfigurationException::create(sprintf(
                '#[%s] without a connection name on %s cannot tell which connection is meant: DbalConfiguration names several (`%s`). '
                . "Name the connection: #[%s('%s')].",
                $attributeWithoutAConnectionName,
                $placementWithoutAConnectionName,
                implode('`, `', $connectionFactories),
                $attributeWithoutAConnectionName,
                $connectionFactories[0],
            ));
        }
        $messagingConfiguration->registerServiceDefinition(self::CONNECTION_MESSAGES_ARE_HANDLED_ON, new Reference($connectionMessagesAreHandledOn));
        $messagingConfiguration->registerServiceDefinition(ObjectManagerOfCurrentConnection::REFERENCE_NAME, new Definition(ObjectManagerOfCurrentConnection::class, [$connectionMessagesAreHandledOn]));

        foreach ($this->connectionsInjectedIntoParameters as $connectionReferenceName => $placement) {
            if ($connectionReferenceName === self::CONNECTION_MESSAGES_ARE_HANDLED_ON || in_array($connectionReferenceName, $connectionFactories, true)) {
                continue;
            }

            $messagingConfiguration->requireReference(
                $connectionReferenceName,
                sprintf(
                    "#[CurrentConnection('%s')] on %s needs a connection registered as `%s`, and there is none. "
                    . 'Register it under that name: a service new %s($dsn), SymfonyConnectionReference::createForConnection($connectionName, $referenceName), LaravelConnectionReference::create($connectionName, $referenceName) or TempestConnectionReference::create($configTag, $referenceName), '
                    . 'or use #[CurrentConnection] without a name for the connection messages are handled on, `%s`.',
                    $connectionReferenceName,
                    $placement,
                    $connectionReferenceName,
                    DbalConnectionReference::DEFAULT,
                    $connectionMessagesAreHandledOn,
                )
            );
        }

        foreach ($this->objectManagersInjectedIntoParameters as $connectionReferenceName => $placement) {
            if ($connectionReferenceName === self::CONNECTION_MESSAGES_ARE_HANDLED_ON || in_array($connectionReferenceName, $connectionFactories, true)) {
                continue;
            }

            $messagingConfiguration->requireReference(
                $connectionReferenceName,
                sprintf(
                    "#[CurrentConnectionObjectManager('%s')] on %s needs a connection registered as `%s`, and there is none. "
                    . 'Register it under that name with Doctrine ORM: SymfonyConnectionReference::createForManagerRegistry($connectionName, referenceName: $referenceName) in a Symfony application, or a service created with %s::createForManagerRegistry($managerRegistry, $connectionName) or, to give every coroutine an ObjectManager of its own, with %s::fromDsnAndConfig($dsn, $pathsToMapping), '
                    . 'or use #[CurrentConnectionObjectManager] without a name for the connection messages are handled on, `%s`.',
                    $connectionReferenceName,
                    $placement,
                    $connectionReferenceName,
                    DbalConnection::class,
                    ManagerRegistryProvider::class,
                    $connectionMessagesAreHandledOn,
                )
            );
        }
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    public function resolveForFlowTesting(array $extensionObjects, ProvidedReferences $providedReferences): array
    {
        return (new FlowTestingDbalConnections($providedReferences, $extensionObjects))->resolveExtensionObjects();
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
