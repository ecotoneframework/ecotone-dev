<?php

namespace Ecotone\Dbal\Recoverability;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Configuration\CustomDeadLetterGateway;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Database\DeadLetterTableManager;
use Ecotone\Dbal\Database\DbalTableManagerReference;
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
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
class DbalDeadLetterModule implements AnnotationModule
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
        $isDeadLetterEnabled = $dbalConfiguration->isDeadLetterEnabled();
        $customDeadLetterGateways = ExtensionObjectResolver::resolve(CustomDeadLetterGateway::class, $extensionObjects);
        $connectionFactoryReference     = $dbalConfiguration->getDeadLetterConnectionReference();

        $messagingConfiguration->registerServiceDefinition(
            DeadLetterTableManager::class,
            new Definition(DeadLetterTableManager::class, [
                DbalDeadLetterHandler::DEFAULT_DEAD_LETTER_TABLE,
                $isDeadLetterEnabled,
            ])
        );

        if (! $isDeadLetterEnabled) {
            return;
        }

        $messagingConfiguration->registerServiceDefinition(DbalDeadLetterConsoleCommand::class, new Definition(DbalDeadLetterConsoleCommand::class));
        $this->registerOneTimeCommand('list', self::LIST_COMMAND_NAME, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('show', self::SHOW_COMMAND_NAME, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('reply', self::REPLAY_COMMAND_NAME, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('replyAll', self::REPLAY_ALL_COMMAND_NAME, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('delete', self::DELETE_COMMAND_NAME, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('help', self::HELP_COMMAND_NAME, $messagingConfiguration, $interfaceToCallRegistry);

        $autoDeclare = $dbalConfiguration->isAutomaticTableInitializationEnabled();

        $this->registerGateway(DeadLetterGateway::class, $connectionFactoryReference, false, $messagingConfiguration, $autoDeclare);
        foreach ($customDeadLetterGateways as $customDeadLetterGateway) {
            $this->registerGateway($customDeadLetterGateway->getGatewayReferenceName(), $customDeadLetterGateway->getConnectionReferenceName(), true, $messagingConfiguration, $autoDeclare);
        }
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof DbalConfiguration || $extensionObject instanceof CustomDeadLetterGateway;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [
            new DbalTableManagerReference(DeadLetterTableManager::class),
        ];
    }

    private function registerOneTimeCommand(string $methodName, string $commandName, Configuration $configuration, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        [$messageHandlerBuilder, $oneTimeCommandConfiguration] = ConsoleCommandModule::prepareConsoleCommandForReference(
            new Reference(DbalDeadLetterConsoleCommand::class),
            new InterfaceToCallReference(DbalDeadLetterConsoleCommand::class, $methodName),
            $commandName,
            true,
            $interfaceToCallRegistry
        );
        $configuration
            ->registerMessageHandler($messageHandlerBuilder)
            ->registerConsoleCommand($oneTimeCommandConfiguration);
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }

    private function registerGateway(string $referenceName, string $connectionFactoryReference, bool $isCustomGateway, Configuration $configuration, bool $autoDeclare = true): void
    {
        if (! $isCustomGateway) {
            $configuration->registerMessageHandler(DbalDeadLetterBuilder::createStore($connectionFactoryReference)->withAutoDeclare($autoDeclare));
        }

        $configuration
            ->registerMessageHandler(DbalDeadLetterBuilder::createDelete($referenceName, $connectionFactoryReference)->withAutoDeclare($autoDeclare))
            ->registerMessageHandler(DbalDeadLetterBuilder::createDeleteAll($referenceName, $connectionFactoryReference)->withAutoDeclare($autoDeclare))
            ->registerMessageHandler(DbalDeadLetterBuilder::createShow($referenceName, $connectionFactoryReference)->withAutoDeclare($autoDeclare))
            ->registerMessageHandler(DbalDeadLetterBuilder::createList($referenceName, $connectionFactoryReference)->withAutoDeclare($autoDeclare))
            ->registerMessageHandler(DbalDeadLetterBuilder::createCount($referenceName, $connectionFactoryReference)->withAutoDeclare($autoDeclare))
            ->registerMessageHandler(DbalDeadLetterBuilder::createReply($referenceName, $connectionFactoryReference)->withAutoDeclare($autoDeclare))
            ->registerMessageHandler(DbalDeadLetterBuilder::createReplyAll($referenceName, $connectionFactoryReference)->withAutoDeclare($autoDeclare))
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
                    'reply',
                    DbalDeadLetterBuilder::getChannelName($referenceName, DbalDeadLetterBuilder::REPLAY_CHANNEL)
                )
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'replyAll',
                    DbalDeadLetterBuilder::getChannelName($referenceName, DbalDeadLetterBuilder::REPLAY_ALL_CHANNEL)
                )
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
