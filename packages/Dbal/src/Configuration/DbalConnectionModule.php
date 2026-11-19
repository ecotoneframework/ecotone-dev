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
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\ObjectManager\ObjectManagerOfCurrentConnection;
use Ecotone\Lite\Test\Configuration\ProvidedReferences;
use Ecotone\Lite\Test\Configuration\ResolvesExtensionObjectsForFlowTesting;
use Ecotone\Messaging\Attribute\EndpointAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
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
        $messagingConfiguration->registerServiceDefinition(ObjectManagerOfCurrentConnection::REFERENCE_NAME, new Definition(ObjectManagerOfCurrentConnection::class));

        foreach ($this->connectionsInjectedIntoParameters as $connectionReferenceName => $placement) {
            if (in_array($connectionReferenceName, $connectionFactories, true)) {
                continue;
            }

            $messagingConfiguration->requireReference(
                $connectionReferenceName,
                sprintf(
                    "#[CurrentConnection('%s')] on %s needs a connection registered as `%s`, and there is none. "
                    . 'Register it under that name: a service new %s($dsn), SymfonyConnectionReference::createForConnection($connectionName, $referenceName), LaravelConnectionReference::create($connectionName, $referenceName) or TempestConnectionReference::create($configTag, $referenceName), '
                    . 'or use #[CurrentConnection] without a name for the default connection `%s`.',
                    $connectionReferenceName,
                    $placement,
                    $connectionReferenceName,
                    DbalConnectionReference::DEFAULT,
                    DbalConnectionReference::DEFAULT,
                )
            );
        }

        foreach ($this->objectManagersInjectedIntoParameters as $connectionReferenceName => $placement) {
            if (in_array($connectionReferenceName, $connectionFactories, true)) {
                continue;
            }

            $messagingConfiguration->requireReference(
                $connectionReferenceName,
                sprintf(
                    "#[CurrentConnectionObjectManager('%s')] on %s needs a connection registered as `%s`, and there is none. "
                    . 'Register it under that name with Doctrine ORM: SymfonyConnectionReference::createForManagerRegistry($connectionName, referenceName: $referenceName) in a Symfony application, or a service created with %s::createForManagerRegistry($managerRegistry, $connectionName), '
                    . 'or use #[CurrentConnectionObjectManager] without a name for the default connection `%s`.',
                    $connectionReferenceName,
                    $placement,
                    $connectionReferenceName,
                    DbalConnection::class,
                    DbalConnectionReference::DEFAULT,
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
