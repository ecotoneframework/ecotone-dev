<?php

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\MessagingCommands\MessagingCommandsModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\ObjectToSerialized\SerializingConverterBuilder;
use Ecotone\Messaging\Conversion\SerializedToObject\DeserializingConverterBuilder;
use Ecotone\Messaging\Conversion\StringToUuid\StringToUuidConverterBuilder;
use Ecotone\Messaging\Conversion\UuidToString\UuidToStringConverterBuilder;
use Ecotone\Messaging\Endpoint\ChannelAdapterConsumerBuilder;
use Ecotone\Messaging\Endpoint\EventDriven\EventDrivenConsumerBuilder;
use Ecotone\Messaging\Endpoint\InboundGatewayEntrypoint;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use Ecotone\Messaging\Handler\Enricher\EnrichGateway;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeadersBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingHandlerBuilder;
use Ecotone\Messaging\Handler\Logger\LoggingService;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\Router\HeaderRouter;
use Ecotone\Messaging\Handler\Router\RouterBuilder;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\NullableMessageChannel;

#[ModuleAnnotation]
class BasicMessagingModule extends NoExternalConfigurationModule implements AnnotationModule
{
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
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof ChannelInterceptorBuilder) {
                $messagingConfiguration->registerChannelInterceptor($extensionObject);
            } elseif ($extensionObject instanceof MessageHandlerBuilder) {
                $messagingConfiguration->registerMessageHandler($extensionObject);
            } elseif ($extensionObject instanceof MessageChannelBuilder) {
                $messagingConfiguration->registerMessageChannel($extensionObject);
            } elseif ($extensionObject instanceof GatewayProxyBuilder) {
                $messagingConfiguration->registerGatewayBuilder($extensionObject);
            } elseif ($extensionObject instanceof ChannelAdapterConsumerBuilder) {
                $messagingConfiguration->registerConsumer($extensionObject);
            } elseif ($extensionObject instanceof PollingMetadata) {
                $messagingConfiguration->registerPollingMetadata($extensionObject);
            }
        }

        $messagingConfiguration->registerConsumerFactory(new EventDrivenConsumerBuilder());
        $messagingConfiguration->registerConsumerFactory(new PollingConsumerBuilder());

        $messagingConfiguration->registerMessageChannel(SimpleMessageChannelBuilder::createPublishSubscribeChannel(MessageHeaders::ERROR_CHANNEL));
        $messagingConfiguration->registerMessageChannel(SimpleMessageChannelBuilder::create(NullableMessageChannel::CHANNEL_NAME, NullableMessageChannel::create()));
        $messagingConfiguration->registerConverter(new UuidToStringConverterBuilder());
        $messagingConfiguration->registerConverter(new StringToUuidConverterBuilder());
        $messagingConfiguration->registerConverter(new SerializingConverterBuilder());
        $messagingConfiguration->registerConverter(new DeserializingConverterBuilder());

        $messagingConfiguration
            ->registerInternalGateway(TypeDescriptor::create(InboundGatewayEntrypoint::class))
            ->registerInternalGateway(TypeDescriptor::create(EnrichGateway::class));

        $messagingConfiguration
            ->registerMessageHandler(
                RouterBuilder::create(
                    new Definition(HeaderRouter::class, [MessagingEntrypoint::ENTRYPOINT]),
                    $interfaceToCallRegistry->getFor(HeaderRouter::class, 'route')
                )
                ->withInputChannelName(MessagingEntrypoint::ENTRYPOINT)
            );

        $messagingConfiguration->registerGatewayBuilder(
            GatewayProxyBuilder::create(
                MessagingEntrypoint::class,
                MessagingEntrypoint::class,
                'send',
                MessagingEntrypoint::ENTRYPOINT
            )->withParameterConverters([
                GatewayPayloadBuilder::create('payload'),
                GatewayHeaderBuilder::create('targetChannel', MessagingEntrypoint::ENTRYPOINT),
            ])
        );
        $messagingConfiguration->registerGatewayBuilder(
            GatewayProxyBuilder::create(
                MessagingEntrypoint::class,
                MessagingEntrypoint::class,
                'sendWithHeaders',
                MessagingEntrypoint::ENTRYPOINT
            )->withParameterConverters([
                GatewayPayloadBuilder::create('payload'),
                GatewayHeadersBuilder::create('headers'),
                GatewayHeaderBuilder::create('targetChannel', MessagingEntrypoint::ENTRYPOINT),
            ])
        );
        $messagingConfiguration->registerGatewayBuilder(
            GatewayProxyBuilder::create(
                MessagingEntrypoint::class,
                MessagingEntrypoint::class,
                'sendWithHeadersWithMessageReply',
                MessagingEntrypoint::ENTRYPOINT
            )->withParameterConverters([
                GatewayPayloadBuilder::create('payload'),
                GatewayHeadersBuilder::create('headers'),
                GatewayHeaderBuilder::create('targetChannel', MessagingEntrypoint::ENTRYPOINT),
            ])
        );
        $messagingConfiguration->registerGatewayBuilder(
            GatewayProxyBuilder::create(
                MessagingEntrypoint::class,
                MessagingEntrypoint::class,
                'sendMessage',
                MessagingEntrypoint::ENTRYPOINT
            )
        );

        $messagingConfiguration->registerGatewayBuilder(
            GatewayProxyBuilder::create(
                MessagingEntrypointWithHeadersPropagation::class,
                MessagingEntrypointWithHeadersPropagation::class,
                'send',
                MessagingEntrypoint::ENTRYPOINT
            )->withParameterConverters([
                GatewayPayloadBuilder::create('payload'),
                GatewayHeaderBuilder::create('targetChannel', MessagingEntrypoint::ENTRYPOINT),
            ])
        );
        $messagingConfiguration->registerGatewayBuilder(
            GatewayProxyBuilder::create(
                MessagingEntrypointWithHeadersPropagation::class,
                MessagingEntrypointWithHeadersPropagation::class,
                'sendWithHeaders',
                MessagingEntrypoint::ENTRYPOINT
            )->withParameterConverters([
                GatewayPayloadBuilder::create('payload'),
                GatewayHeadersBuilder::create('headers'),
                GatewayHeaderBuilder::create('targetChannel', MessagingEntrypoint::ENTRYPOINT),
            ])
        );
        $messagingConfiguration->registerGatewayBuilder(
            GatewayProxyBuilder::create(
                MessagingEntrypointWithHeadersPropagation::class,
                MessagingEntrypointWithHeadersPropagation::class,
                'sendWithHeadersWithMessageReply',
                MessagingEntrypoint::ENTRYPOINT
            )->withParameterConverters([
                GatewayPayloadBuilder::create('payload'),
                GatewayHeadersBuilder::create('headers'),
                GatewayHeaderBuilder::create('targetChannel', MessagingEntrypoint::ENTRYPOINT),
            ])
        );
        $messagingConfiguration->registerGatewayBuilder(
            GatewayProxyBuilder::create(
                MessagingEntrypointWithHeadersPropagation::class,
                MessagingEntrypointWithHeadersPropagation::class,
                'sendMessage',
                MessagingEntrypoint::ENTRYPOINT
            )
        );

        $messagingConfiguration->registerGatewayBuilder(
            GatewayProxyBuilder::create(
                ConsoleCommandRunner::class,
                ConsoleCommandRunner::class,
                'execute',
                MessagingCommandsModule::ECOTONE_EXECUTE_CONSOLE_COMMAND_EXECUTOR
            )->withParameterConverters([
                GatewayHeaderBuilder::create('commandName', MessagingCommandsModule::ECOTONE_CONSOLE_COMMAND_NAME),
                GatewayPayloadBuilder::create('parameters'),
            ])
        );

        $messagingConfiguration->registerServiceDefinition(
            LoggingService::class,
            new Definition(
                LoggingService::class,
                [
                    Reference::to(ConversionService::REFERENCE_NAME),
                    Reference::to(LoggingHandlerBuilder::LOGGER_REFERENCE),
                ]
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof ChannelInterceptorBuilder
            ||
            $extensionObject instanceof MessageHandlerBuilder
            ||
            $extensionObject instanceof MessageChannelBuilder
            ||
            $extensionObject instanceof GatewayProxyBuilder
            ||
            $extensionObject instanceof ChannelAdapterConsumerBuilder
            ||
            $extensionObject instanceof PollingMetadata;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
