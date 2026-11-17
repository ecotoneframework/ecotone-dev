<?php

/*
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\EndpointRunnerReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\LicenceDecider;
use Ecotone\Messaging\Endpoint\PollingConsumer\InterceptedConsumerRunner;
use Ecotone\Messaging\Handler\Gateway\Gateway;
use Ecotone\Messaging\Handler\Gateway\GatewayInternalProcessor;
use Ecotone\Messaging\Handler\Gathering\CollectingMessageHandler;
use Ecotone\Messaging\Handler\Gathering\GatheringMessageHandler;
use Ecotone\Messaging\Handler\Gathering\GatheringSplitterProcessor;
use Ecotone\Messaging\Handler\Gathering\UngatheredSplitterProcessor;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\ChainedMessageProcessor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMessageProcessor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInterceptor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvokerAggregateObjectResolver;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvokerStaticObjectResolver;
use Ecotone\Messaging\Handler\Processor\MethodInvokerProcessor;
use Ecotone\Messaging\Handler\RequestReplyProducer;
use Ecotone\Messaging\Handler\Router\InvocationRouter;
use Ecotone\Messaging\Handler\Router\Router;
use Ecotone\Messaging\Handler\Router\RouterProcessor;
use Ecotone\Messaging\Handler\Splitter\SplitterHandler;
use Ecotone\Modelling\AggregateFlow\LoadAggregate\LoadAggregateMessageProcessor;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\SaveAggregateService;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\SaveAggregateTestSetupService;
use Ecotone\Modelling\AggregateIdentifierRetrevingService;
use Ecotone\Modelling\Config\Routing\TypeAliasResolverProcessor;
use Ecotone\Modelling\FetchAggregate;

final class MessageFlowChainReader
{
    public const UNRECOGNISED = 'unrecognised layer ';
    private const GENERATED_ID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{64}/';
    private const HANDLER = 'handler';
    private const ENTERPRISE_ONLY_LAYERS = [
        GatheringMessageHandler::class => 'gatherer',
        CollectingMessageHandler::class => 'collector',
        GatheringSplitterProcessor::class => 'gathering of the split messages',
        UngatheredSplitterProcessor::class => 'refusal to split inside a gathered flow',
    ];

    private const NAMED_PROCESSORS = [
        TypeAliasResolverProcessor::class => 'type alias of the routing key resolved to its class',
        AggregateIdentifierRetrevingService::class => 'aggregate identifier read from the message',
        LoadAggregateMessageProcessor::class => 'aggregate loaded from its repository',
        FetchAggregate::class => 'loaded aggregate returned as the result',
        SaveAggregateService::class => 'aggregate saved to its repository',
        SaveAggregateTestSetupService::class => 'aggregate state stored for a test',
    ];

    /** @var array<string, string> */
    private array $interceptorRoles = [];
    private string $invokedByRequestReply = '';

    private function __construct(
        private readonly ContainerBuilder $builder,
        private readonly Configuration $configuration,
        private readonly InterfaceToCallRegistry $interfaceToCallRegistry,
    ) {
    }

    /**
     * @param array<string, string> $handlerNames
     * @return array<string, list<string>>
     */
    public static function read(ContainerBuilder $builder, Configuration $configuration, InterfaceToCallRegistry $interfaceToCallRegistry, array $handlerNames): array
    {
        return (new self($builder, $configuration, $interfaceToCallRegistry))->chainsOf($handlerNames);
    }

    /**
     * @param array<string, string> $handlerNames
     * @return array<string, list<string>>
     */
    private function chainsOf(array $handlerNames): array
    {
        $registered = $this->configuration->getRegisteredMethodInterceptors();
        foreach (['before', 'after'] as $position) {
            foreach ($registered[$position] as $interceptor) {
                if ($interceptor instanceof MethodInterceptorBuilder) {
                    $this->interceptorRoles[$interceptor->getInterceptingInterfaceReference()->getClassName() . '::' . $interceptor->getInterceptingInterfaceReference()->getMethodName()] = $position . ' interceptor';
                }
            }
        }
        $chains = [];
        $subscribedSoFar = [];
        foreach ($this->configuration->getRegisteredMessageHandlersInConsumerOrder() as $handler) {
            $endpointId = (string) $handler->getEndpointId();
            $channelName = $handler->getInputMessageChannelName();
            $channel = $this->stable($channelName);
            if ($this->builder->has((new EndpointRunnerReference($endpointId))->getId())) {
                $chains[$handlerNames[$endpointId]] = ['polling consumer of ' . $channel, ...$this->layersOf(new EndpointRunnerReference($endpointId), self::HANDLER)];
                continue;
            }
            $channelDefinition = $this->builder->has((new ChannelReference($channelName))->getId()) ? $this->builder->getDefinition((new ChannelReference($channelName))->getId()) : null;
            $subscriptions = [];
            foreach ($channelDefinition instanceof Definition ? $channelDefinition->getMethodCalls() : [] as $methodCall) {
                if ($methodCall->getMethodName() === 'subscribe') {
                    $subscriptions[] = $methodCall->getArguments()[0];
                }
            }
            $subscribedSoFar[$channelName] ??= 0;
            $subscription = $subscriptions[$subscribedSoFar[$channelName]++] ?? null;
            $chains[$handlerNames[$endpointId]] = $subscription === null
                ? [self::UNRECOGNISED . 'consumer: channel ' . $channel . ' has neither a polling consumer nor a subscription for this handler']
                : ['event driven consumer of ' . $channel, ...$this->layersOf($subscription, self::HANDLER)];
        }
        ksort($chains, SORT_STRING);

        return $chains;
    }

    /**
     * @return list<string>
     */
    private function layersOf(mixed $compiled, string $role): array
    {
        if ($compiled instanceof Reference) {
            return $this->builder->has($compiled->getId())
                ? $this->layersOf($this->builder->getDefinition($compiled->getId()), $role)
                : [self::UNRECOGNISED . 'reference ' . $this->stable($compiled->getId())];
        }
        if (! $compiled instanceof Definition) {
            return [self::UNRECOGNISED . get_debug_type($compiled)];
        }
        if ($this->isLicenceChoice($compiled)) {
            return $this->layersOfLicenceChoice($compiled, $role);
        }
        $arguments = $compiled->getArguments();

        return match ($compiled->getClassName()) {
            InterceptedConsumerRunner::class => $this->layersOf($arguments[0], $role),
            Gateway::class => $this->layersOf($arguments[3], $role),
            GatewayInternalProcessor::class => $this->layersOf($arguments[3], $role),
            DirectChannel::class => $this->layersOf($arguments[1], $role),
            AroundMessageProcessor::class => [...$this->aroundInterceptors($arguments[2]), ...$this->layersOf($arguments[0], $role)],
            ChainedMessageProcessor::class => array_merge(...array_map(fn (mixed $processor): array => $this->layersOf($processor, $role), array_values($arguments[0]))),
            MethodInvokerProcessor::class, InvocationRouter::class => $this->layersOf($arguments[0], $role),
            MethodInvoker::class => [...$this->aroundInterceptors($arguments[4] ?? []), $this->invoked($arguments[0], (string) $arguments[1], $role)],
            RouterProcessor::class => ['router ' . ($arguments[0] instanceof Definition ? $arguments[0]->getClassName() : self::UNRECOGNISED . get_debug_type($arguments[0]))],
            RequestReplyProducer::class => [
                ...$this->layersOfRequestReply($arguments[1], (string) ($arguments[6] ?? ''), $role),
                ...($arguments[0] instanceof Reference
                    ? ['output channel ' . $this->channelNameOf($arguments[0]), 'part completion ' . $this->partCompletion($arguments[5]) . ', when the handler returns nothing']
                    : ['routing slip: the result goes to the next channel on it', 'part completion ' . $this->partCompletion($arguments[5]), 'reply channel of the message']),
            ],
            SplitterHandler::class => [
                ...$this->layersOf($arguments[1], 'splitter'),
                'each split message to ' . ($arguments[0] instanceof Reference ? 'output channel ' . $this->channelNameOf($arguments[0]) : 'the next channel of the routing slip or the reply channel of the message'),
            ],
            Router::class => [...$this->layersOf($arguments[1], 'router'), 'the channel the router resolves', 'part completion ' . $this->partCompletion($arguments[6])],
            default => [self::NAMED_PROCESSORS[$compiled->getClassName()] ?? self::UNRECOGNISED . $compiled->getClassName()],
        };
    }

    /**
     * @return list<string>
     */
    private function layersOfRequestReply(mixed $processor, string $invoked, string $role): array
    {
        $this->invokedByRequestReply = $invoked;
        $layers = $this->layersOf($processor, $role);
        $this->invokedByRequestReply = '';

        return $layers;
    }

    /**
     * @return list<string>
     */
    private function layersOfLicenceChoice(Definition $choice, string $role): array
    {
        [, $openCore, $enterprise] = $choice->getArguments();
        $enterpriseDefinition = $this->builder->has($enterprise) ? $this->builder->getDefinition($enterprise) : null;
        $wrapsOpenCore = $enterpriseDefinition instanceof Definition
            && ($enterpriseDefinition->getArguments()[0] ?? null) instanceof Reference
            && $enterpriseDefinition->getArguments()[0]->getId() === $openCore;
        if (! $wrapsOpenCore) {
            return [self::UNRECOGNISED . 'licence choice between ' . $this->stable($enterprise) . ' and ' . $this->stable($openCore)];
        }

        return [
            (self::ENTERPRISE_ONLY_LAYERS[$enterpriseDefinition->getClassName()] ?? self::UNRECOGNISED . $enterpriseDefinition->getClassName()) . ' (' . $enterpriseDefinition->getClassName() . ', with an Enterprise licence only)',
            ...$this->layersOf(new Reference($openCore), $role),
        ];
    }

    private function isLicenceChoice(Definition $definition): bool
    {
        return $definition->hasFactory() && $definition->getFactory() === [LicenceDecider::class, 'decide'];
    }

    /**
     * @return list<string>
     */
    private function aroundInterceptors(mixed $interceptors): array
    {
        $layers = [];
        foreach (is_array($interceptors) ? $interceptors : [] as $interceptor) {
            $layers[] = $interceptor instanceof Definition && $interceptor->getClassName() === AroundMethodInterceptor::class
                ? 'around interceptor ' . $this->classOf($interceptor->getArguments()[0]) . '::' . $interceptor->getArguments()[1]
                : self::UNRECOGNISED . ($interceptor instanceof Definition ? $interceptor->getClassName() : get_debug_type($interceptor));
        }

        return $layers;
    }

    private function invoked(mixed $objectResolver, string $method, string $role): string
    {
        $class = match (true) {
            $objectResolver instanceof Definition && $objectResolver->getClassName() === MethodInvokerStaticObjectResolver::class => $this->classOf($objectResolver->getArguments()[0]),
            $objectResolver instanceof Definition && $objectResolver->getClassName() === MethodInvokerAggregateObjectResolver::class && str_ends_with($this->invokedByRequestReply, '::' . $method) => substr($this->invokedByRequestReply, 0, -strlen('::' . $method)),
            default => null,
        };
        if ($class === null) {
            return self::UNRECOGNISED . ($objectResolver instanceof Definition ? $objectResolver->getClassName() : get_debug_type($objectResolver)) . ' invoking ' . $method;
        }
        if ($objectResolver->getClassName() === MethodInvokerAggregateObjectResolver::class) {
            $role .= ' on the loaded aggregate';
        } elseif ($role === self::HANDLER && $this->invokedByRequestReply !== '' && ! $this->isInvokedByRequestReply($class, $method)) {
            $role = 'step';
        }
        $parameterTypes = [];
        if (class_exists($class) || interface_exists($class)) {
            foreach ($this->interfaceToCallRegistry->getFor($class, $method)->getInterfaceParameters() as $parameter) {
                $parameterTypes[] = $parameter->getTypeHint() . ' $' . $parameter->getName();
            }
        }

        return ($this->interceptorRoles[$class . '::' . $method] ?? $role) . ' ' . $this->stable($class) . '::' . $method . '(' . implode(', ', $parameterTypes) . ')';
    }

    private function isInvokedByRequestReply(string $class, string $method): bool
    {
        [$invokedClass, $invokedMethod] = explode('::', $this->invokedByRequestReply, 2) + [1 => ''];

        return $method === $invokedMethod && ($class === $invokedClass || is_a($class, $invokedClass, true));
    }

    private function classOf(mixed $object): string
    {
        return match (true) {
            $object instanceof Definition => $object->getClassName(),
            $object instanceof Reference && (class_exists($object->getId()) || interface_exists($object->getId())) => $object->getId(),
            $object instanceof Reference && $this->builder->has($object->getId()) && $this->builder->getDefinition($object->getId()) instanceof Definition => $this->builder->getDefinition($object->getId())->getClassName(),
            $object instanceof Reference => $this->stable($object->getId()),
            is_string($object) => $object,
            default => get_debug_type($object),
        };
    }

    private function partCompletion(mixed $partCompletion): string
    {
        $definition = $partCompletion instanceof Reference && $this->builder->has($partCompletion->getId()) ? $this->builder->getDefinition($partCompletion->getId()) : $partCompletion;
        if (! $definition instanceof Definition) {
            return self::UNRECOGNISED . get_debug_type($definition);
        }

        return $this->isLicenceChoice($definition)
            ? $definition->getArguments()[2] . ' with an Enterprise licence, ' . $definition->getArguments()[1] . ' in open core'
            : $definition->getClassName();
    }

    private function channelNameOf(Reference $channel): string
    {
        return $this->stable(substr($channel->getId(), strlen((new ChannelReference(''))->getId())));
    }

    private function stable(string $name): string
    {
        return (string) preg_replace(self::GENERATED_ID, '{generated}', $name);
    }
}
