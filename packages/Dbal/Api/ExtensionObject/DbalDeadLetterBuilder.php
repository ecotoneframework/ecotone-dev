<?php

namespace Ecotone\Api\Dbal\ExtensionObject;

use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Recoverability\DeadLetterHandler;
use Ecotone\Dbal\Recoverability\DeadLetterStore;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
class DbalDeadLetterBuilder extends InputOutputMessageHandlerBuilder
{
    public const LIMIT_HEADER  = 'ecotone.dbal.deadletter.limit';
    public const OFFSET_HEADER = 'ecotone.dbal.deadletter.offset';

    public const LIST_CHANNEL  = 'ecotone.dbal.deadletter.list';
    public const SHOW_CHANNEL       = 'ecotone.dbal.deadletter.show';
    public const COUNT_CHANNEL       = 'ecotone.dbal.deadletter.count';
    public const REPLAY_CHANNEL     = 'ecotone.dbal.deadletter.replay';
    public const REPLAY_ALL_CHANNEL = 'ecotone.dbal.deadletter.replayAll';
    public const DELETE_CHANNEL     = 'ecotone.dbal.deadletter.delete';
    public const DELETE_ALL_CHANNEL     = 'ecotone.dbal.deadletter.deleteAll';
    public const STORE_CHANNEL     = 'dbal_dead_letter';

    private string $methodName;
    private string $referenceName;
    private array $parameterConverters;

    private function __construct(string $methodName, string $referenceName, string $inputChannelName, array $parameterConverters)
    {
        $this->methodName              = $methodName;
        $this->referenceName           = $referenceName;
        $this->parameterConverters     = $parameterConverters;
        $this->inputMessageChannelName = $inputChannelName;
    }

    public static function getChannelName(string $referenceName, string $actionChannel): string
    {
        return $referenceName . '.' . $actionChannel;
    }

    public static function storeReferenceFor(string $referenceName): string
    {
        return DeadLetterStore::class . '.' . $referenceName;
    }

    public static function createList(string $referenceName): self
    {
        return new self(
            'list',
            $referenceName,
            self::getChannelName($referenceName, self::LIST_CHANNEL),
            [
                HeaderBuilder::create('limit', self::LIMIT_HEADER),
                HeaderBuilder::create('offset', self::OFFSET_HEADER),
            ]
        );
    }

    public static function createShow(string $referenceName): self
    {
        return new self(
            'show',
            $referenceName,
            self::getChannelName($referenceName, self::SHOW_CHANNEL),
            [
                PayloadBuilder::create('messageId'),
                HeaderBuilder::createOptional('replyChannel', MessageHeaders::REPLY_CHANNEL),
            ]
        );
    }

    public static function createCount(string $referenceName): self
    {
        return new self(
            'count',
            $referenceName,
            self::getChannelName($referenceName, self::COUNT_CHANNEL),
            []
        );
    }

    public static function createReplay(string $referenceName): self
    {
        return new self(
            'replay',
            $referenceName,
            self::getChannelName($referenceName, self::REPLAY_CHANNEL),
            []
        );
    }

    public static function createReplayAll(string $referenceName): self
    {
        return new self(
            'replayAll',
            $referenceName,
            self::getChannelName($referenceName, self::REPLAY_ALL_CHANNEL),
            [
                ReferenceBuilder::create('messagingEntrypoint', MessagingEntrypointService::class),
            ]
        );
    }

    public static function createDelete(string $referenceName): self
    {
        return new self(
            'delete',
            $referenceName,
            self::getChannelName($referenceName, self::DELETE_CHANNEL),
            []
        );
    }

    public static function createDeleteAll(string $referenceName): self
    {
        return new self(
            'deleteAll',
            $referenceName,
            self::getChannelName($referenceName, self::DELETE_ALL_CHANNEL),
            []
        );
    }

    public static function createStore(string $referenceName, string $inputChannelName): self
    {
        return new self(
            'store',
            $referenceName,
            $inputChannelName,
            []
        );
    }

    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(DeadLetterHandler::class, $this->methodName);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $deadLetterHandlerReference = DeadLetterHandler::class . '.' . $this->referenceName;
        if (! $builder->has($deadLetterHandlerReference)) {
            $builder->register($deadLetterHandlerReference, new Definition(DeadLetterHandler::class, [
                Reference::to(self::storeReferenceFor($this->referenceName)),
                Reference::to(MessageBuilder::class),
            ]));
        }

        $messageHandler = ServiceActivatorBuilder::create(
            $deadLetterHandlerReference,
            new InterfaceToCallReference(DeadLetterHandler::class, $this->methodName),
        );

        return $messageHandler
            ->withMethodParameterConverters($this->parameterConverters)
            ->withEndpointId($this->getEndpointId())
            ->withInputChannelName($this->getInputMessageChannelName())
            ->withOutputMessageChannel($this->getOutputMessageChannelName())
            ->compile($builder);
    }

    public function getEndpointId(): ?string
    {
        return $this->getInputMessageChannelName() . '.endpoint';
    }

    public function withEndpointId(string $endpointId): self
    {
        return $this;
    }

}
