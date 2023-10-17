<?php

namespace Ecotone\Dbal\Recoverability;

use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\MessageHeaders;

class DbalDeadLetterBuilder extends InputOutputMessageHandlerBuilder
{
    public const LIMIT_HEADER  = 'ecotone.dbal.deadletter.limit';
    public const OFFSET_HEADER = 'ecotone.dbal.deadletter.offset';

    public const LIST_CHANNEL  = 'ecotone.dbal.deadletter.list';
    public const SHOW_CHANNEL       = 'ecotone.dbal.deadletter.show';
    public const COUNT_CHANNEL       = 'ecotone.dbal.deadletter.count';
    public const REPLAY_CHANNEL     = 'ecotone.dbal.deadletter.reply';
    public const REPLAY_ALL_CHANNEL = 'ecotone.dbal.deadletter.replyAll';
    public const DELETE_CHANNEL     = 'ecotone.dbal.deadletter.delete';
    public const DELETE_ALL_CHANNEL     = 'ecotone.dbal.deadletter.deleteAll';
    public const STORE_CHANNEL     = 'dbal_dead_letter';

    private string $methodName;
    private string $connectionReferenceName;
    private array $parameterConverters;

    private function __construct(string $methodName, string $connectionReferenceName, string $inputChannelName, array $parameterConverters)
    {
        $this->methodName              = $methodName;
        $this->connectionReferenceName = $connectionReferenceName;
        $this->parameterConverters     = $parameterConverters;
        $this->inputMessageChannelName = $inputChannelName;
    }

    public static function getChannelName(string $referenceName, string $actionChannel): string
    {
        return $referenceName . '.' . $actionChannel;
    }

    public static function createList(string $referenceName, string $connectionReferenceName): self
    {
        return new self(
            'list',
            $connectionReferenceName,
            self::getChannelName($referenceName, self::LIST_CHANNEL),
            [
                HeaderBuilder::create('limit', self::LIMIT_HEADER),
                HeaderBuilder::create('offset', self::OFFSET_HEADER),
            ]
        );
    }

    public static function createShow(string $referenceName, string $connectionReferenceName): self
    {
        return new self(
            'show',
            $connectionReferenceName,
            self::getChannelName($referenceName, self::SHOW_CHANNEL),
            [
                PayloadBuilder::create('messageId'),
                HeaderBuilder::createOptional('replyChannel', MessageHeaders::REPLY_CHANNEL),
            ]
        );
    }

    public static function createCount(string $referenceName, string $connectionReferenceName): self
    {
        return new self(
            'count',
            $connectionReferenceName,
            self::getChannelName($referenceName, self::COUNT_CHANNEL),
            []
        );
    }

    public static function createReply(string $referenceName, string $connectionReferenceName): self
    {
        return new self(
            'reply',
            $connectionReferenceName,
            self::getChannelName($referenceName, self::REPLAY_CHANNEL),
            []
        );
    }

    public static function createReplyAll(string $referenceName, string $connectionReferenceName): self
    {
        return new self(
            'replyAll',
            $connectionReferenceName,
            self::getChannelName($referenceName, self::REPLAY_ALL_CHANNEL),
            [
                ReferenceBuilder::create('messagingEntrypoint', MessagingEntrypoint::class),
            ]
        );
    }

    public static function createDelete(string $referenceName, string $connectionReferenceName): self
    {
        return new self(
            'delete',
            $connectionReferenceName,
            self::getChannelName($referenceName, self::DELETE_CHANNEL),
            []
        );
    }

    public static function createDeleteAll(string $referenceName, string $connectionReferenceName): self
    {
        return new self(
            'deleteAll',
            $connectionReferenceName,
            self::getChannelName($referenceName, self::DELETE_ALL_CHANNEL),
            []
        );
    }

    public static function createStore(string $connectionReferenceName): self
    {
        return new self(
            'store',
            $connectionReferenceName,
            self::STORE_CHANNEL,
            []
        );
    }

    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(DbalDeadLetterHandler::class, $this->methodName);
    }

    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): MessageHandler
    {
        $messageHandler = ServiceActivatorBuilder::createWithDirectReference(
            new DbalDeadLetterHandler(
                CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($referenceSearchService->get($this->connectionReferenceName))),
                DefaultHeaderMapper::createAllHeadersMapping(),
                $referenceSearchService->get(ConversionService::REFERENCE_NAME)
            ),
            $this->methodName
        );

        foreach ($this->orderedAroundInterceptors as $orderedAroundInterceptor) {
            $messageHandler->addAroundInterceptor($orderedAroundInterceptor);
        }

        return $messageHandler
            ->withMethodParameterConverters($this->parameterConverters)
            ->withEndpointId($this->getEndpointId())
            ->withInputChannelName($this->getInputMessageChannelName())
            ->withOutputMessageChannel($this->getOutputMessageChannelName())
            ->build($channelResolver, $referenceSearchService);
    }

    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return [
            $interfaceToCallRegistry->getFor(DbalDeadLetterHandler::class, 'list'),
            $interfaceToCallRegistry->getFor(DbalDeadLetterHandler::class, 'show'),
            $interfaceToCallRegistry->getFor(DbalDeadLetterHandler::class, 'reply'),
            $interfaceToCallRegistry->getFor(DbalDeadLetterHandler::class, 'replyAll'),
            $interfaceToCallRegistry->getFor(DbalDeadLetterHandler::class, 'delete'),
            $interfaceToCallRegistry->getFor(DbalDeadLetterHandler::class, 'store'),
        ];
    }

    public function getEndpointId(): ?string
    {
        return $this->getInputMessageChannelName() . '.endpoint';
    }

    public function withEndpointId(string $endpointId): self
    {
        return $this;
    }

    public function getRequiredReferenceNames(): array
    {
        return [];
    }
}
