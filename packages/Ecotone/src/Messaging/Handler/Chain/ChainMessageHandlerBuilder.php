<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Chain;

use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\ContainerImplementation;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\InMemoryChannelResolver;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithOutputChannel;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Exception;
use Ramsey\Uuid\Uuid;

/**
 * Class ChainMessageHandlerBuilder
 * @package Ecotone\Messaging\Handler\Chain
 * @author  Dariusz Gafka <dgafka.mail@gmail.com>
 */
class ChainMessageHandlerBuilder extends InputOutputMessageHandlerBuilder implements CompilableBuilder
{
    /**
     * @var MessageHandlerBuilderWithOutputChannel[]
     */
    private array $chainedMessageHandlerBuilders;
    /**
     * @var string[]
     */
    private array $requiredReferences = [];
    /**
     * @var MessageHandlerBuilder|null
     */
    private ?MessageHandlerBuilder $outputMessageHandler = null;

    private ?int $interceptedHandlerOffset = null;
    private ?Reference $compiled = null;

    /**
     * ChainMessageHandlerBuilder constructor.
     */
    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    public function chainInterceptedHandler(MessageHandlerBuilderWithOutputChannel $messageHandler): self
    {
        Assert::null($this->interceptedHandlerOffset, "Cannot register two intercepted handlers {$messageHandler}. Already have {$this->interceptedHandlerOffset}");

        $this->chain($messageHandler);
        $this->interceptedHandlerOffset = array_key_last($this->chainedMessageHandlerBuilders);
        $this->requiredInterceptorReferenceNames = $messageHandler->getRequiredInterceptorNames();

        return $this;
    }

    public function chain(MessageHandlerBuilderWithOutputChannel $messageHandler): self
    {
        $this->requiredReferences = array_merge($this->requiredReferences, MessagingSystemConfiguration::resolveRequiredReferenceForBuilder($messageHandler));
        $outputChannelToKeep = $messageHandler->getOutputMessageChannelName();
        $messageHandler = $messageHandler
            ->withInputChannelName('')
            ->withOutputMessageChannel('');

        if ($outputChannelToKeep) {
            $messageHandler = ChainMessageHandlerBuilder::create()
                ->chainInterceptedHandler($messageHandler)
                ->chain(new OutputChannelKeeperBuilder($outputChannelToKeep));
        }

        $this->chainedMessageHandlerBuilders[] = $messageHandler;

        return $this;
    }

    /**
     * Do not combine with outputMessageChannel. Output message handler can be router and should contain output channel by his own
     *
     * @param MessageHandlerBuilder $outputMessageHandler
     * @return ChainMessageHandlerBuilder
     */
    public function withOutputMessageHandler(MessageHandlerBuilder $outputMessageHandler): self
    {
        $this->requiredReferences = array_merge($this->requiredReferences, MessagingSystemConfiguration::resolveRequiredReferenceForBuilder($outputMessageHandler));
        $this->outputMessageHandler = $outputMessageHandler;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): MessageHandler
    {
        if ($this->outputMessageHandler && $this->outputMessageChannelName) {
            throw InvalidArgumentException::create("Can't configure output message handler and output message channel for chain handler");
        }
        if ($this->compiled) {
            return $referenceSearchService->get(ContainerImplementation::REFERENCE_ID)->get((string) $this->compiled);
        }

        if (count($this->chainedMessageHandlerBuilders) === 1 && ! $this->outputMessageHandler) {
            $singleHandler = $this->chainedMessageHandlerBuilders[0]
                ->withOutputMessageChannel($this->getOutputMessageChannelName());

            foreach ($this->orderedAroundInterceptors as $aroundInterceptorReference) {
                $singleHandler = $singleHandler->addAroundInterceptor($aroundInterceptorReference);
            }
            return $singleHandler->build($channelResolver, $referenceSearchService);
        }

        /** @var DirectChannel[] $bridgeChannels */
        $bridgeChannels = [];
        $messageHandlersToChain = $this->chainedMessageHandlerBuilders;

        if ($this->outputMessageHandler) {
            $messageHandlersToChain[] = $this->outputMessageHandler;
        }

        $baseKey = Uuid::uuid4()->toString();
        for ($key = 1; $key < count($messageHandlersToChain); $key++) {
            $bridgeChannels[$baseKey . $key] = DirectChannel::create($this->inputMessageChannelName . '_chain.' . $baseKey . $key);
        }
        $requestChannel = DirectChannel::create($this->inputMessageChannelName . '_chain.' . $baseKey);
        $bridgeChannels[$baseKey] = $requestChannel;

        $customChannelResolver = InMemoryChannelResolver::createWithChannelResolver($channelResolver, $bridgeChannels);

        $serviceActivator = ServiceActivatorBuilder::createWithDirectReference(new ChainForwardPublisher($requestChannel, (bool)$this->outputMessageChannelName), 'forward')
            ->withOutputMessageChannel($this->outputMessageChannelName);

        if (is_null($this->interceptedHandlerOffset)) {
            foreach ($this->orderedAroundInterceptors as $aroundInterceptorReference) {
                $serviceActivator = $serviceActivator->addAroundInterceptor($aroundInterceptorReference);
            }
        }

        for ($key = 0; $key < count($messageHandlersToChain); $key++) {
            $currentKey = $baseKey . $key;
            $messageHandlerBuilder = $messageHandlersToChain[$key];
            $nextHandlerKey = ($key + 1);
            $previousHandlerKey = ($key - 1);

            if ($key === $this->interceptedHandlerOffset) {
                foreach ($this->orderedAroundInterceptors as $aroundInterceptorReference) {
                    $messageHandlerBuilder = $messageHandlerBuilder->addAroundInterceptor($aroundInterceptorReference);
                }
            }

            if ($this->hasNextHandler($messageHandlersToChain, $nextHandlerKey)) {
                $messageHandlerBuilder = $messageHandlerBuilder->withOutputMessageChannel($baseKey . $nextHandlerKey);
            }

            $messageHandler = $messageHandlerBuilder->build($customChannelResolver, $referenceSearchService);

            if ($this->hasPreviousHandler($messageHandlersToChain, $previousHandlerKey)) {
                $customChannelResolver->resolve($currentKey)->subscribe($messageHandler);
            }

            if ($key === 0) {
                $requestChannel->subscribe($messageHandler);
            }
        }

        return $serviceActivator->build($channelResolver, $referenceSearchService);
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        if ($this->outputMessageHandler && $this->outputMessageChannelName) {
            throw InvalidArgumentException::create("Can't configure output message handler and output message channel for chain handler");
        }
        if (! $this->canBeCompiled()) {
            return null;
        }

        if (count($this->chainedMessageHandlerBuilders) === 1 && ! $this->outputMessageHandler) {
            $singleHandler = $this->chainedMessageHandlerBuilders[0]
                ->withOutputMessageChannel($this->getOutputMessageChannelName());

            foreach ($this->orderedAroundInterceptors as $aroundInterceptorReference) {
                $singleHandler = $singleHandler->addAroundInterceptor($aroundInterceptorReference);
            }
            return $singleHandler->compile($builder);
        }

        $messageHandlersToChain = $this->chainedMessageHandlerBuilders;

        if ($this->outputMessageHandler) {
            $messageHandlersToChain[] = $this->outputMessageHandler;
        }

        $baseKey = Uuid::uuid4()->toString();
        foreach ($messageHandlersToChain as $key => $messageHandlerBuilder) {
            $nextHandlerKey = ($key + 1);
            $currentChannelName = $this->inputMessageChannelName . '_chain.' . $baseKey . $key;
            if ($key === $this->interceptedHandlerOffset) {
                foreach ($this->orderedAroundInterceptors as $aroundInterceptorReference) {
                    $messageHandlerBuilder = $messageHandlerBuilder->addAroundInterceptor($aroundInterceptorReference);
                }
            }
            if ($this->hasNextHandler($messageHandlersToChain, $nextHandlerKey)) {
                $messageHandlerBuilder = $messageHandlerBuilder->withOutputMessageChannel($this->inputMessageChannelName . '_chain.' . $baseKey . $nextHandlerKey);
            }
            $messageHandlerReference = $messageHandlerBuilder->compile($builder);
            if (! $messageHandlerReference) {
                // Cant compile
                throw InvalidArgumentException::create("Can't compile {$messageHandlerBuilder}");
                return null;
            }
            $builder->register(new ChannelReference($currentChannelName), new Definition(DirectChannel::class, [
                $currentChannelName,
                $messageHandlerReference,
            ]));
        }

        $chainForwardPublisherReference = $builder->register(
            \uniqid('chainforwardpublisher-'),
            new Definition(ChainForwardPublisher::class, [
                new ChannelReference($this->inputMessageChannelName . '_chain.' . $baseKey . '0'),
                (bool)$this->outputMessageChannelName
            ]));

        $interfaceToCall = $builder->getInterfaceToCall(new InterfaceToCallReference(ChainForwardPublisher::class, 'forward'));

        $serviceActivator = ServiceActivatorBuilder::create($chainForwardPublisherReference->getId(), $interfaceToCall)
            ->withOutputMessageChannel($this->outputMessageChannelName);

        if (is_null($this->interceptedHandlerOffset)) {
            foreach ($this->orderedAroundInterceptors as $aroundInterceptorReference) {
                $serviceActivator = $serviceActivator->addAroundInterceptor($aroundInterceptorReference);
            }
        }

        return $this->compiled = $serviceActivator->compile($builder);
    }

    private function canBeCompiled(): bool
    {
        if ($this->outputMessageHandler && ! $this->outputMessageHandler instanceof CompilableBuilder) {
            throw InvalidArgumentException::create("Output message handler {$this->outputMessageHandler} must be compilable");
            return false;
        }
        foreach ($this->chainedMessageHandlerBuilders as $chainedMessageHandlerBuilder) {
            if (!($chainedMessageHandlerBuilder instanceof CompilableBuilder)) {
                throw InvalidArgumentException::create("Chained message handler {$chainedMessageHandlerBuilder} must be compilable");
                return false;
            }
        }
        return true;
    }

    /**
     * @param array $messageHandlersToChain
     * @param $nextHandlerKey
     * @return bool
     */
    private function hasNextHandler(array $messageHandlersToChain, $nextHandlerKey): bool
    {
        return isset($messageHandlersToChain[$nextHandlerKey]);
    }

    /**
     * @param array $messageHandlersToChain
     * @param $previousHandlerKey
     * @return bool
     */
    private function hasPreviousHandler(array $messageHandlersToChain, $previousHandlerKey): bool
    {
        return isset($messageHandlersToChain[$previousHandlerKey]);
    }

    /**
     * @inheritDoc
     */
    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        if (! is_null($this->interceptedHandlerOffset)) {
            return $this->chainedMessageHandlerBuilders[$this->interceptedHandlerOffset]->getInterceptedInterface($interfaceToCallRegistry);
        }

        return $interfaceToCallRegistry->getFor(ChainForwardPublisher::class, 'forward');
    }

    /**
     * @inheritDoc
     */
    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        $relatedReferences = [];
        if ($this->outputMessageHandler) {
            $relatedReferences = array_merge($relatedReferences, $this->outputMessageHandler->resolveRelatedInterfaces($interfaceToCallRegistry));
        }

        foreach ($this->chainedMessageHandlerBuilders as $chainedMessageHandlerBuilder) {
            foreach ($chainedMessageHandlerBuilder->resolveRelatedInterfaces($interfaceToCallRegistry) as $resolveRelatedReference) {
                $relatedReferences[] = $resolveRelatedReference;
            }
        }

        return $relatedReferences;
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferenceNames(): array
    {
        return $this->requiredReferences;
    }
}
