<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ConsoleCommandModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

#[ModuleAnnotation]
/**
 * Module that collects DbalTableManager extension objects and registers database setup commands.
 *
 * licence Apache-2.0
 */
class DatabaseSetupModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(
            DbalConfiguration::class,
            $extensionObjects,
            DbalConfiguration::createWithDefaults()
        );

        $tableManagerReferences = ExtensionObjectResolver::resolve(DbalTableManagerReference::class, $extensionObjects);

        $connectionReference = $dbalConfiguration->getDefaultConnectionReferenceNames()[0] ?? \Enqueue\Dbal\DbalConnectionFactory::class;

        $tableManagerRefs = array_map(
            fn (DbalTableManagerReference $ref) => new Reference($ref->getReferenceName()),
            $tableManagerReferences
        );

        $messagingConfiguration->registerServiceDefinition(
            DatabaseSetupManager::class,
            new Definition(DatabaseSetupManager::class, [
                new Definition(DbalReconnectableConnectionFactory::class, [
                    new Reference($connectionReference),
                ]),
                $tableManagerRefs,
            ])
        );

        $messagingConfiguration->registerServiceDefinition(
            DatabaseSetupCommand::class,
            new Definition(DatabaseSetupCommand::class, [
                new Reference(DatabaseSetupManager::class),
            ])
        );

        $messagingConfiguration->registerServiceDefinition(
            DatabaseDropCommand::class,
            new Definition(DatabaseDropCommand::class, [
                new Reference(DatabaseSetupManager::class),
            ])
        );

        $this->registerConsoleCommand(
            'setup',
            'ecotone:migration:database:setup',
            DatabaseSetupCommand::class,
            $messagingConfiguration,
            $interfaceToCallRegistry
        );

        $this->registerConsoleCommand(
            'drop',
            'ecotone:migration:database:drop',
            DatabaseDropCommand::class,
            $messagingConfiguration,
            $interfaceToCallRegistry
        );
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof DbalTableManagerReference
            || $extensionObject instanceof DbalConfiguration;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }

    private function registerConsoleCommand(
        string $methodName,
        string $commandName,
        string $className,
        Configuration $configuration,
        InterfaceToCallRegistry $interfaceToCallRegistry
    ): void {
        [$messageHandlerBuilder, $oneTimeCommandConfiguration] = ConsoleCommandModule::prepareConsoleCommandForReference(
            new Reference($className),
            new InterfaceToCallReference($className, $methodName),
            $commandName,
            true,
            $interfaceToCallRegistry
        );

        $configuration
            ->registerMessageHandler($messageHandlerBuilder)
            ->registerConsoleCommand($oneTimeCommandConfiguration);
    }
}
