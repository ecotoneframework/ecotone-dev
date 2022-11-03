<?php

namespace Ecotone\Dbal\Recoverability;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Configuration\CustomDeadLetterGateway;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Configuration\DbalModule;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ConsoleCommandModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

#[ModuleAnnotation]
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
    public function prepare(Configuration $configuration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());
        $isDeadLetterEnabled = $dbalConfiguration->isDeadLetterEnabled();
        $customDeadLetterGateways = ExtensionObjectResolver::resolve(CustomDeadLetterGateway::class, $extensionObjects);
        $connectionFactoryReference     = $dbalConfiguration->getDeadLetterConnectionReference();

        if (! $isDeadLetterEnabled) {
            return;
        }

        $this->registerOneTimeCommand('list', self::LIST_COMMAND_NAME, $configuration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('show', self::SHOW_COMMAND_NAME, $configuration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('reply', self::REPLAY_COMMAND_NAME, $configuration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('replyAll', self::REPLAY_ALL_COMMAND_NAME, $configuration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('delete', self::DELETE_COMMAND_NAME, $configuration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('help', self::HELP_COMMAND_NAME, $configuration, $interfaceToCallRegistry);

        $this->registerGateway(DeadLetterGateway::class, $connectionFactoryReference, false, $configuration);
        foreach ($customDeadLetterGateways as $customDeadLetterGateway) {
            $this->registerGateway($customDeadLetterGateway->getGatewayReferenceName(), $customDeadLetterGateway->getConnectionReferenceName(), true, $configuration);
        }
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof DbalConfiguration || $extensionObject instanceof CustomDeadLetterGateway;
    }

    /**
     * @inheritDoc
     */
    public function getRelatedReferences(): array
    {
        return [];
    }

    public function getModuleExtensions(array $serviceExtensions): array
    {
        return [];
    }

    private function registerOneTimeCommand(string $methodName, string $commandName, Configuration $configuration, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        [$messageHandlerBuilder, $oneTimeCommandConfiguration] = ConsoleCommandModule::prepareConsoleCommandForDirectObject(
            new DbalDeadLetterConsoleCommand(),
            $methodName,
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

    private function registerGateway(string $referenceName, string $connectionFactoryReference, bool $isCustomGateway, Configuration $configuration): void
    {
        if (! $isCustomGateway) {
            $configuration->registerMessageHandler(DbalDeadLetterBuilder::createStore($connectionFactoryReference));
        }

        $configuration
            ->registerMessageHandler(DbalDeadLetterBuilder::createDelete($referenceName, $connectionFactoryReference))
            ->registerMessageHandler(DbalDeadLetterBuilder::createShow($referenceName, $connectionFactoryReference))
            ->registerMessageHandler(DbalDeadLetterBuilder::createList($referenceName, $connectionFactoryReference))
            ->registerMessageHandler(DbalDeadLetterBuilder::createCount($referenceName, $connectionFactoryReference))
            ->registerMessageHandler(DbalDeadLetterBuilder::createReply($referenceName, $connectionFactoryReference))
            ->registerMessageHandler(DbalDeadLetterBuilder::createReplyAll($referenceName, $connectionFactoryReference))
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
            );
    }
}
