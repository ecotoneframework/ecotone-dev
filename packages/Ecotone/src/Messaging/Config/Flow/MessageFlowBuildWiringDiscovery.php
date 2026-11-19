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

use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\AsynchronousModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\InterceptedEndpoint;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithOutputChannel;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\InterceptorWithPointCut;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Handler\Router\RouterBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\UninterruptibleServiceActivator;

final class MessageFlowBuildWiringDiscovery
{
    private const GENERATED_ID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/';

    /** @var array<string, FlowNode> */
    private array $nodes = [];
    /** @var array<string, FlowEdge> */
    private array $edges = [];
    /** @var array<string, string> */
    private array $stableNames = [];
    /** @var array<string, list<string>> */
    private array $consumersOfChannel = [];
    /** @var array<string, string> */
    private array $entryChannels = [];
    /** @var array<string, true> */
    private array $takenEndpointNodeIds = [];
    /** @var array<string, true> */
    private array $claimedApplicationNodeIds = [];

    private function __construct(
        private readonly Configuration $configuration,
        private readonly InterfaceToCallRegistry $interfaceToCallRegistry,
        private readonly MessageFlowGraph $declared,
    ) {
    }

    public static function discover(Configuration $configuration, InterfaceToCallRegistry $interfaceToCallRegistry, MessageFlowGraph $declared): MessageFlowBuildWiring
    {
        return (new self(configuration: $configuration, interfaceToCallRegistry: $interfaceToCallRegistry, declared: $declared))->run();
    }

    private function run(): MessageFlowBuildWiring
    {
        $handlers = $this->configuration->getRegisteredMessageHandlers();
        foreach ($handlers as $handler) {
            $invoked = $this->invokedInterfaceOf($handler);
            if ($invoked !== null && $this->isGenerated((string) $handler->getEndpointId())) {
                $this->stableNames[(string) $handler->getEndpointId()] = $invoked->getInterfaceName() . '::' . $invoked->getMethodName();
            }
        }
        foreach ($this->configuration->getRegisteredMessageChannels() as $channel) {
            $this->channelNode($channel->getMessageChannelName());
        }
        foreach ($this->configuration->getRegisteredGateways() as $gateway) {
            $this->discoverGateway($gateway);
        }
        $asynchronousRoutes = [];
        foreach ($handlers as $handler) {
            $nodeId = $this->discoverHandler($handler);
            if ($handler instanceof UninterruptibleServiceActivator && isset($this->configuration->getRegisteredMessageChannels()[AsynchronousModule::getHandlerExecutionChannel($handler->getInputMessageChannelName())])) {
                $asynchronousRoutes[] = [$handler->getOutputMessageChannelName(), AsynchronousModule::getHandlerExecutionChannel($handler->getInputMessageChannelName())];
            }
            unset($nodeId);
        }
        foreach ($asynchronousRoutes as [$asynchronousChannel, $executionChannel]) {
            foreach ($this->consumersOfChannel[$asynchronousChannel] ?? [] as $consumerId) {
                $this->edge(from: $consumerId, to: $this->channelNode($executionChannel), kind: FlowEdgeKind::ROUTES_TO, attributes: ['via' => 'routingSlip']);
            }
        }
        foreach ($this->configuration->getRegisteredConsumers() as $consumer) {
            $this->discoverConsumer($consumer->getEndpointId());
        }
        ksort($this->nodes, SORT_STRING);
        ksort($this->edges, SORT_STRING);
        ksort($this->entryChannels, SORT_STRING);

        return new MessageFlowBuildWiring(
            array_map(fn (FlowNode $node): array => $node->toArray(), array_values($this->nodes)),
            array_map(fn (FlowEdge $edge): array => $edge->toArray(), array_values($this->edges)),
            $this->entryChannels,
        );
    }

    private function discoverGateway(GatewayProxyBuilder $gateway): void
    {
        $id = 'gateway:' . $gateway->getInterfaceName() . '::' . $gateway->getRelatedMethodName();
        if (! $this->declared->hasNode($id)) {
            $this->nodes[$id] = new FlowNode($id, FlowNodeKind::GATEWAY, $this->shortName($gateway->getInterfaceName()) . '::' . $gateway->getRelatedMethodName(), [
                'interface' => $gateway->getInterfaceName(),
                'method' => $gateway->getRelatedMethodName(),
                'requestChannel' => $this->stable($gateway->getRequestChannelName()),
                'build' => true,
                'registeredBy' => $this->configuration->getRegisteringModule(Configuration::GATEWAY, $gateway->getInterfaceName() . '::' . $gateway->getRelatedMethodName()),
            ]);
        }
        $this->edge(from: $id, to: $this->channelNode($gateway->getRequestChannelName()), kind: FlowEdgeKind::SENDS_TO, attributes: []);
        $this->discoverInterceptorsAround(interceptedId: $id, intercepted: $gateway->getInterceptedInterface($this->interfaceToCallRegistry), endpointAnnotations: $gateway->getEndpointAnnotations(), requiredInterceptorNames: $gateway->getRequiredInterceptorNames());
    }

    private function discoverHandler(MessageHandlerBuilder $handler): string
    {
        $invoked = $this->invokedInterfaceOf($handler);
        $inputChannel = $handler->getInputMessageChannelName();
        $id = $invoked === null ? null : $this->applicationNodeOf($invoked, (string) $handler->getEndpointId());
        if ($id !== null) {
            $busRoutingChannel = str_ends_with($inputChannel, AsynchronousModule::HANDLER_EXECUTION_CHANNEL_SUFFIX) ? substr($inputChannel, 0, -strlen(AsynchronousModule::HANDLER_EXECUTION_CHANNEL_SUFFIX)) : $inputChannel;
            $this->entryChannels[$id] = 'channel:' . $this->stable(isset($this->configuration->getRegisteredMessageChannels()[$busRoutingChannel]) ? $busRoutingChannel : $inputChannel);
        } else {
            $id = $this->endpointNodeId($this->isGenerated((string) $handler->getEndpointId()) || (string) $handler->getEndpointId() === '' ? $inputChannel : (string) $handler->getEndpointId());
            $this->nodes[$id] = new FlowNode(
                $id,
                $handler instanceof RouterBuilder ? FlowNodeKind::ROUTER : FlowNodeKind::INTERNAL_HANDLER,
                ($invoked === null ? '' : $this->shortName((string) $invoked->getInterfaceName()) . '::' . $invoked->getMethodName() . ' on ') . $this->stable($inputChannel),
                array_filter([
                    'class' => $invoked?->getInterfaceName(),
                    'method' => $invoked?->getMethodName(),
                    'inputChannel' => $this->stable($inputChannel),
                    'build' => true,
                    'registeredBy' => $this->configuration->getRegisteringModule(Configuration::HANDLER, (string) $handler->getEndpointId()),
                ], fn (mixed $value): bool => $value !== null),
            );
        }
        $this->consumersOfChannel[$inputChannel][] = $id;
        $this->edge(from: $this->channelNode($inputChannel), to: $id, kind: FlowEdgeKind::INPUT, attributes: ['consumer' => $this->configuration->isPollableChannel($inputChannel) ? 'polling' : 'eventDriven']);
        $outputChannel = match (true) {
            $handler instanceof MessageHandlerBuilderWithOutputChannel, $handler instanceof UninterruptibleServiceActivator => $handler->getOutputMessageChannelName(),
            default => '',
        };
        if ($outputChannel !== '') {
            $this->edge(from: $id, to: $this->channelNode($outputChannel), kind: FlowEdgeKind::OUTPUT, attributes: []);
        }
        if ($handler instanceof InterceptedEndpoint && $invoked !== null) {
            $this->discoverInterceptorsAround(interceptedId: $id, intercepted: $invoked, endpointAnnotations: $handler->getEndpointAnnotations(), requiredInterceptorNames: $handler->getRequiredInterceptorNames());
        }

        return $id;
    }

    private function discoverConsumer(string $endpointId): void
    {
        if ($this->isGenerated($endpointId)) {
            return;
        }
        foreach ($this->declared->nodes() as $node) {
            if (($node->attributes['endpointId'] ?? null) === $endpointId) {
                return;
            }
        }
        $id = $this->endpointNodeId($endpointId);
        $this->nodes[$id] = new FlowNode($id, FlowNodeKind::INTERNAL_HANDLER, $endpointId, ['endpointId' => $endpointId, 'consumer' => 'inboundChannelAdapter', 'build' => true, 'registeredBy' => $this->configuration->getRegisteringModule(Configuration::CONSUMER, $endpointId)]);
    }

    /**
     * @param iterable<AttributeDefinition> $endpointAnnotations
     * @param iterable<string> $requiredInterceptorNames
     */
    private function discoverInterceptorsAround(string $interceptedId, InterfaceToCall $intercepted, iterable $endpointAnnotations, iterable $requiredInterceptorNames): void
    {
        $annotations = [];
        foreach ($endpointAnnotations as $endpointAnnotation) {
            $annotations[] = $endpointAnnotation->instance();
        }
        foreach ($this->configuration->getRegisteredMethodInterceptors() as $position => $interceptors) {
            foreach ($interceptors as $interceptor) {
                if (! $this->isAround(interceptor: $interceptor, intercepted: $intercepted, annotations: $annotations, requiredInterceptorNames: $requiredInterceptorNames)) {
                    continue;
                }
                [$class, $method] = $interceptor instanceof AroundInterceptorBuilder
                    ? [(string) $interceptor->getInterceptingInterface()->getInterfaceName(), (string) $interceptor->getInterceptingInterface()->getMethodName()]
                    : ($interceptor instanceof MethodInterceptorBuilder ? [$interceptor->getInterceptingInterfaceReference()->getClassName(), $interceptor->getInterceptingInterfaceReference()->getMethodName()] : [$interceptor::class, '']);
                $id = 'interceptor:' . $class . '::' . $method;
                if (! $this->declared->hasNode($id)) {
                    $this->nodes[$id] ??= new FlowNode($id, FlowNodeKind::INTERCEPTOR, $this->shortName($class) . '::' . $method, [
                        'class' => $class,
                        'method' => $method,
                        'position' => $position,
                        'build' => true,
                        'registeredBy' => $this->configuration->getRegisteringModule(Configuration::INTERCEPTOR, (string) spl_object_id($interceptor)),
                    ]);
                }
                $this->edge(from: $interceptedId, to: $id, kind: FlowEdgeKind::INTERCEPTED_BY, attributes: []);
            }
        }
    }

    /**
     * @param list<object> $annotations
     * @param iterable<string> $requiredInterceptorNames
     */
    private function isAround(InterceptorWithPointCut $interceptor, InterfaceToCall $intercepted, array $annotations, iterable $requiredInterceptorNames): bool
    {
        foreach ($requiredInterceptorNames as $requiredInterceptorName) {
            if ($interceptor->hasName($requiredInterceptorName)) {
                return true;
            }
        }

        return $interceptor->doesItCutWith($intercepted, $annotations);
    }

    private function invokedInterfaceOf(MessageHandlerBuilder $handler): ?InterfaceToCall
    {
        return match (true) {
            $handler instanceof InterceptedEndpoint => $handler->getInterceptedInterface($this->interfaceToCallRegistry),
            $handler instanceof UninterruptibleServiceActivator => $handler->getInvokedInterface($this->interfaceToCallRegistry),
            default => null,
        };
    }

    private function applicationNodeOf(InterfaceToCall $invoked, string $endpointId): ?string
    {
        $fallback = null;
        foreach ($this->declared->nodes() as $node) {
            if ($node->kind === FlowNodeKind::INTERCEPTOR || ($node->attributes['class'] ?? null) !== $invoked->getInterfaceName() || ($node->attributes['method'] ?? null) !== $invoked->getMethodName()) {
                continue;
            }
            if (($node->attributes['endpointId'] ?? null) === $endpointId) {
                return $this->claim($node->id);
            }
            if (! isset($this->claimedApplicationNodeIds[$node->id]) && ($node->attributes['endpointId'] ?? null) === null) {
                $fallback ??= $node->id;
            }
        }

        return $fallback === null ? null : $this->claim($fallback);
    }

    private function claim(string $applicationNodeId): string
    {
        $this->claimedApplicationNodeIds[$applicationNodeId] = true;

        return $applicationNodeId;
    }

    private function endpointNodeId(string $name): string
    {
        $id = 'endpoint:' . $this->stable($name);
        for ($occurrence = 2; isset($this->takenEndpointNodeIds[$id]); $occurrence++) {
            $id = 'endpoint:' . $this->stable($name) . '#' . $occurrence;
        }
        $this->takenEndpointNodeIds[$id] = true;

        return $id;
    }

    private function channelNode(string $channelName): string
    {
        $name = $this->stable($channelName);
        $id = 'channel:' . $name;
        $this->nodes[$id] ??= $this->declared->hasNode($id)
            ? $this->declared->node($id)
            : new FlowNode($id, FlowNodeKind::CHANNEL, $name, ['name' => $name, 'pollable' => $this->configuration->isPollableChannel($channelName), 'build' => true, 'registeredBy' => $this->configuration->getRegisteringModule(Configuration::CHANNEL, $channelName)]);

        return $id;
    }

    /**
     * @param array<string, scalar|array<scalar>|null> $attributes
     */
    private function edge(string $from, string $to, FlowEdgeKind $kind, array $attributes): void
    {
        $edge = new FlowEdge(from: $from, to: $to, kind: $kind, attributes: $attributes + ['build' => true]);
        $this->edges[$edge->identity()] ??= $edge;
    }

    private function stable(string $name): string
    {
        return (string) preg_replace(self::GENERATED_ID, '{generated}', strtr($name, $this->stableNames));
    }

    private function isGenerated(string $endpointId): bool
    {
        return preg_match(self::GENERATED_ID, $endpointId) === 1;
    }

    private function shortName(string $class): string
    {
        $segments = explode('\\', $class);

        return end($segments);
    }
}
