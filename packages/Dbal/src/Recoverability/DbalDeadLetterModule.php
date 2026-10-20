<?php

namespace Ecotone\Dbal\Recoverability;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Dbal\Configuration\CustomDeadLetterGateway;
use Ecotone\Dbal\Database\DbalTableManagerReference;
use Ecotone\Dbal\Database\DeadLetterTableManager;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ConsoleCommandModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\BootstrapConfiguration;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConsoleInvocationResolver;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\Support\MessageBuilder;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class DbalDeadLetterModule implements AnnotationModule
{
    public const HELP_COMMAND_NAME = 'ecotone:deadletter:help';
    public const LIST_COMMAND_NAME            = 'ecotone:deadletter:list';
    public const SHOW_COMMAND_NAME       = 'ecotone:deadletter:show';
    public const REPLAY_COMMAND_NAME     = 'ecotone:deadletter:replay';
    public const REPLAY_ALL_COMMAND_NAME = 'ecotone:deadletter:replayAll';
    public const DELETE_COMMAND_NAME     = 'ecotone:deadletter:delete';

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        $isDeadLetterEnabled = $dbalConfiguration->deadLetterEnabled;
        $customDeadLetterGateways = ExtensionObjectResolver::resolve(CustomDeadLetterGateway::class, $extensionObjects);
        $connectionFactoryReference     = $dbalConfiguration->getDeadLetterConnectionReference();
        $consoleInvocationPrefix = ConsoleInvocationResolver::resolveConsolePrefix(ExtensionObjectResolver::resolveUnique(BootstrapConfiguration::class, $extensionObjects, BootstrapConfiguration::createWithDefaults()));

        $messagingConfiguration->registerServiceDefinition(
            DeadLetterTableManager::class,
            new Definition(DeadLetterTableManager::class, [
                DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE,
                $isDeadLetterEnabled && ! $dbalConfiguration->deadLetterInMemory,
                $consoleInvocationPrefix,
                $connectionFactoryReference,
            ])
        );

        if (! $isDeadLetterEnabled) {
            return;
        }

        $messagingConfiguration->registerServiceDefinition(DbalDeadLetterConsoleCommand::class, new Definition(DbalDeadLetterConsoleCommand::class));
        $this->registerOneTimeCommand('list', self::LIST_COMMAND_NAME, $messagingConfiguration, $interfaceToCallRegistry, 'Lists dead letter messages');
        $this->registerOneTimeCommand('show', self::SHOW_COMMAND_NAME, $messagingConfiguration, $interfaceToCallRegistry, 'Shows details of given dead letter message');
        $this->registerOneTimeCommand('replay', self::REPLAY_COMMAND_NAME, $messagingConfiguration, $interfaceToCallRegistry, 'Replays given dead letter message');
        $this->registerOneTimeCommand('replayAll', self::REPLAY_ALL_COMMAND_NAME, $messagingConfiguration, $interfaceToCallRegistry, 'Replays all dead letter messages');
        $this->registerOneTimeCommand('delete', self::DELETE_COMMAND_NAME, $messagingConfiguration, $interfaceToCallRegistry, 'Deletes given dead letter message');
        $this->registerOneTimeCommand('help', self::HELP_COMMAND_NAME, $messagingConfiguration, $interfaceToCallRegistry, 'Shows help for dead letter management commands');

        $this->registerGateway(DeadLetterGateway::class, $connectionFactoryReference, DbalDeadLetterBuilder::STORE_CHANNEL, $dbalConfiguration->deadLetterInMemory, $messagingConfiguration);
        foreach ($customDeadLetterGateways as $customDeadLetterGateway) {
            $referenceName = $customDeadLetterGateway->getGatewayReferenceName();
            $this->registerGateway($referenceName, $customDeadLetterGateway->getConnectionReferenceName(), DbalDeadLetterBuilder::getChannelName($referenceName, DbalDeadLetterBuilder::STORE_CHANNEL), $dbalConfiguration->deadLetterInMemory, $messagingConfiguration);
        }
    }

    /**
     * @inheritDoc
     */
    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $serviceExtensions, DbalConfiguration::createWithDefaults());

        $connectionReferences = [$dbalConfiguration->getDeadLetterConnectionReference()];
        foreach (ExtensionObjectResolver::resolve(CustomDeadLetterGateway::class, $serviceExtensions) as $customDeadLetterGateway) {
            $connectionReferences[] = $customDeadLetterGateway->getConnectionReferenceName();
        }

        return array_map(
            fn (string $connectionReference): DbalTableManagerReference => new DbalTableManagerReference(DeadLetterTableManager::class, $connectionReference),
            array_values(array_unique($connectionReferences)),
        );
    }

    private function registerOneTimeCommand(string $methodName, string $commandName, Configuration $configuration, InterfaceToCallRegistry $interfaceToCallRegistry, string $description = ''): void
    {
        [$messageHandlerBuilder, $oneTimeCommandConfiguration] = ConsoleCommandModule::prepareConsoleCommandForReference(
            new Reference(DbalDeadLetterConsoleCommand::class),
            new InterfaceToCallReference(DbalDeadLetterConsoleCommand::class, $methodName),
            $commandName,
            true,
            $interfaceToCallRegistry,
            $description
        );
        $configuration
            ->registerMessageHandler($messageHandlerBuilder)
            ->registerConsoleCommand($oneTimeCommandConfiguration);
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }

    private function registerGateway(string $referenceName, string $connectionFactoryReference, string $storeChannelName, bool $isInMemory, Configuration $configuration): void
    {
        $configuration->registerServiceDefinition(
            DbalDeadLetterBuilder::storeReferenceFor($referenceName),
            $isInMemory
                ? new Definition(InMemoryDeadLetterStore::class, [
                    DefaultHeaderMapper::createAllHeadersMapping(),
                    Reference::to(ConversionService::REFERENCE_NAME),
                    Reference::to(MessageBuilder::class),
                ])
                : new Definition(DbalDeadLetterStore::class, [
                    new Definition(CachedConnectionFactory::class, [
                        new Definition(DbalReconnectableConnectionFactory::class, [
                            new Reference($connectionFactoryReference),
                            new Reference(EcotoneClockInterface::class),
                        ]),
                    ], 'createFor'),
                    DefaultHeaderMapper::createAllHeadersMapping(),
                    Reference::to(ConversionService::REFERENCE_NAME),
                    Reference::to(RetryRunner::class),
                    Reference::to(DeadLetterTableManager::class),
                    Reference::to(MessageBuilder::class),
                ])
        );

        $configuration
            ->registerMessageHandler(DbalDeadLetterBuilder::createStore($referenceName, $storeChannelName))
            ->registerMessageHandler(DbalDeadLetterBuilder::createDelete($referenceName))
            ->registerMessageHandler(DbalDeadLetterBuilder::createDeleteAll($referenceName))
            ->registerMessageHandler(DbalDeadLetterBuilder::createShow($referenceName))
            ->registerMessageHandler(DbalDeadLetterBuilder::createList($referenceName))
            ->registerMessageHandler(DbalDeadLetterBuilder::createCount($referenceName))
            ->registerMessageHandler(DbalDeadLetterBuilder::createReplay($referenceName))
            ->registerMessageHandler(DbalDeadLetterBuilder::createReplayAll($referenceName))
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'store',
                    $storeChannelName
                )
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'list',
                    DbalDeadLetterBuilder::getChannelName($referenceName, DbalDeadLetterBuilder::LIST_CHANNEL)
                )
                    ->withParameterConverters([
                        GatewayHeaderBuilder::create('limit', DbalDeadLetterBuilder::LIMIT_HEADER),
                        GatewayHeaderBuilder::create('offset', DbalDeadLetterBuilder::OFFSET_HEADER),
                    ])
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'show',
                    DbalDeadLetterBuilder::getChannelName($referenceName, DbalDeadLetterBuilder::SHOW_CHANNEL)
                )
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'count',
                    DbalDeadLetterBuilder::getChannelName($referenceName, DbalDeadLetterBuilder::COUNT_CHANNEL)
                )
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'replay',
                    DbalDeadLetterBuilder::getChannelName($referenceName, DbalDeadLetterBuilder::REPLAY_CHANNEL)
                )
                    ->withParameterConverters([
                        GatewayHeaderBuilder::create('targetChannel', DbalDeadLetterBuilder::TARGET_CHANNEL_HEADER),
                    ])
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'replayAll',
                    DbalDeadLetterBuilder::getChannelName($referenceName, DbalDeadLetterBuilder::REPLAY_ALL_CHANNEL)
                )
                    ->withParameterConverters([
                        GatewayHeaderBuilder::create('targetChannel', DbalDeadLetterBuilder::TARGET_CHANNEL_HEADER),
                    ])
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'delete',
                    DbalDeadLetterBuilder::getChannelName($referenceName, DbalDeadLetterBuilder::DELETE_CHANNEL)
                )
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'deleteAll',
                    DbalDeadLetterBuilder::getChannelName($referenceName, DbalDeadLetterBuilder::DELETE_ALL_CHANNEL)
                )
            );
    }
}
