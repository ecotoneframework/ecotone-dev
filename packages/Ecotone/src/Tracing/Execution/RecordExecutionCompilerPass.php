<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Messaging\Channel\Collector\CollectorSenderInterceptor;
use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Channel\EventDrivenChannelInterceptorAdapter;
use Ecotone\Messaging\Channel\PollableChannelInterceptorAdapter;
use Ecotone\Messaging\Channel\PublishSubscribeChannel;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\MethodInterceptor\BeforeSendChannelInterceptor;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\Bridge\Bridge;
use Ecotone\Messaging\Handler\Gateway\Gateway;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupStore;
use Ecotone\Messaging\Handler\Gathering\GatheringMessageHandler;
use Ecotone\Messaging\Handler\Gathering\GatheringPartCompletion;
use Ecotone\Messaging\Handler\Processor\InterceptedChainDefinition;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMessageProcessor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInterceptor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\MethodInvocationConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ValueConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvokerStaticObjectResolver;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\OrchestratorResultMessageConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvokerProcessor;
use Ecotone\Messaging\Handler\RequestReplyProducer;
use Ecotone\Messaging\Handler\Router\InvocationRouter;
use Ecotone\Messaging\Handler\Router\Router;
use Ecotone\Messaging\Handler\Router\RouterProcessor;
use Ecotone\Messaging\Handler\Transformer\TransformerResultToMessageConverter;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Config\Routing\BusRoutingKeyResolver;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Ecotone\Tracing\Tracer;

/**
 * licence Apache-2.0
 */
final class RecordExecutionCompilerPass implements CompilerPass
{
    public const PRESEND = 'presend';
    public const ROUTER = 'router';
    public const TRANSFORMER = 'transformer';
    public const SPLITTER = 'splitter';
    public const BRIDGE = 'bridge';
    public const ORCHESTRATOR = 'orchestrator';
    private const OWN_ROLES = [self::TRANSFORMER, self::SPLITTER, self::ORCHESTRATOR];
    private const SPLITTER_PROCESSOR_PREFIX = 'ecotone.gathering.splitter.core.';
    private const CHANNEL_ID_PREFIX = 'channel-';
    private const COLLECTOR_SENDER_PREFIX = CollectorSenderInterceptor::class . '.';
    private const POLL_HOUSEKEEPING = [
        MessageHeadersPropagatorInterceptor::class . '::enablePollingConsumerPropagation',
        MessageHeadersPropagatorInterceptor::class . '::disablePollingConsumerPropagation',
    ];
    private const FLOW_TESTING_NAMESPACE = 'Ecotone\\Lite\\Test\\';
    private const SEND_RECORDING_PREFIX = 'ecotone.execution.send.';
    private const REPLY_RECORDING_PREFIX = 'ecotone.execution.reply.';

    /** @var array<int, true> */
    private array $visited = [];

    public function process(ContainerBuilder $builder): void
    {
        $this->visited = [];
        if ($builder->has(GatheredGroupStore::class)) {
            $builder->replace(GatheredGroupStore::class, new Definition(RecordedGatheredGroupStore::class, [$builder->getDefinition(GatheredGroupStore::class), new Reference(Tracer::class)]));
        }
        foreach ($builder->getDefinitions() as $id => $definition) {
            if ($definition instanceof Definition && str_starts_with($id, self::CHANNEL_ID_PREFIX)) {
                $definition = $this->recordChannel(substr($id, strlen(self::CHANNEL_ID_PREFIX)), $definition, $builder);
                $builder->replace($id, $definition);
            }
            $recorded = $this->recorded($definition, str_starts_with($id, self::SPLITTER_PROCESSOR_PREFIX) ? self::SPLITTER : null, $builder);
            if ($recorded !== $definition) {
                $builder->replace($id, $recorded);
            }
        }
    }

    private function recordChannel(string $channelName, Definition $channel, ContainerBuilder $builder): Definition
    {
        $arguments = $channel->getArguments();
        $innerChannel = $channel;
        if (in_array($channel->getClassName(), [PollableChannelInterceptorAdapter::class, EventDrivenChannelInterceptorAdapter::class], true)) {
            $innerChannel = $arguments[0];
            $interceptorClasses = array_map(fn (mixed $interceptor): string => $this->classOf($interceptor, $builder), array_values($arguments[1]));
            $recording = $builder->register(self::SEND_RECORDING_PREFIX . $channelName, new Definition(ChannelSendRecording::class, [
                $channelName,
                array_map(fn (string $class, mixed $interceptor): string|Reference => $interceptor instanceof Reference && ! class_exists($class) ? $interceptor : $class, $interceptorClasses, array_values($arguments[1])),
                array_values(array_filter($interceptorClasses, fn (string $class): bool => ! self::isFlowTesting($class))) === [],
                new Reference(MessageBuilder::class),
                new Reference(Tracer::class),
            ]));
            $interceptors = [];
            foreach (array_values($arguments[1]) as $position => $interceptor) {
                $interceptors[] = $interceptor;
                $interceptors[] = new Definition(ChannelInterceptorRan::class, [$recording, $position]);
            }
            $recordedChannel = new Definition($channel->getClassName() === PollableChannelInterceptorAdapter::class ? RecordedPollableChannelInterceptorAdapter::class : RecordedEventDrivenChannelInterceptorAdapter::class, [$arguments[0], $interceptors, $recording]);
            foreach ($channel->getMethodCalls() as $methodCall) {
                $recordedChannel->addMethodCall($methodCall->getMethodName(), $methodCall->getArguments());
            }
            $channel = $recordedChannel;
        }
        $handOver = match ($innerChannel instanceof Definition ? $innerChannel->getClassName() : '') {
            DirectChannel::class => 'direct channel ' . $channelName,
            PublishSubscribeChannel::class => 'publish-subscribe channel ' . $channelName,
            default => 'channel ' . $channelName,
        };
        foreach ($channel->getMethodCalls() as $methodCall) {
            if ($methodCall->getMethodName() === 'subscribe') {
                $subscription = $methodCall->getArguments();
                $subscription[0] = new Definition(RecordedHandOver::class, [$handOver, $subscription[0], new Reference(Tracer::class)]);
                $methodCall->replaceArguments($subscription);
            }
        }

        return $channel;
    }

    private function recorded(mixed $value, ?string $role, ContainerBuilder $builder): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $element) {
                $value[$key] = $this->recorded($element, $role, $builder);
            }

            return $value;
        }
        if (! $value instanceof Definition || isset($this->visited[spl_object_id($value)])) {
            return $value;
        }
        $this->visited[spl_object_id($value)] = true;
        $arguments = $value->getArguments();
        if ($value instanceof InterceptedChainDefinition) {
            foreach ($arguments[0] as $position => $processor) {
                $chainRole = $value->roles[$position] ?? InterceptedChainDefinition::STEP;
                $arguments[0][$position] = $this->recorded($processor, $chainRole === InterceptedChainDefinition::HANDLER && in_array($role, self::OWN_ROLES, true) ? $role : $chainRole, $builder);
            }
        } else {
            $arguments = match ($value->getClassName()) {
                MethodInvoker::class => $this->withRecordedInvocation($arguments, 4, $role ?? InterceptedChainDefinition::HANDLER, $builder),
                AroundMessageProcessor::class => $this->withRecordedInvocation($arguments, 2, null, $builder),
                Gateway::class => $this->withRecordedEntry($arguments, $builder),
                BeforeSendChannelInterceptor::class => $this->recorded($arguments, self::PRESEND, $builder),
                InvocationRouter::class => $this->recorded($arguments, self::ROUTER, $builder),
                RouterProcessor::class => $this->withRecordedRoutes($this->recorded($arguments, null, $builder)),
                Router::class => $this->withRecordedRouterRoutes($this->recorded($arguments, null, $builder)),
                MethodInvokerProcessor::class => $this->recorded($arguments, self::roleOfProcessor($arguments, $role), $builder),
                default => $this->recorded($arguments, $role, $builder),
            };
        }
        $value->replaceArguments($arguments);
        foreach ($value->getMethodCalls() as $methodCall) {
            $methodCall->replaceArguments($this->recorded($methodCall->getArguments(), null, $builder));
        }

        return match ($value->getClassName()) {
            RequestReplyProducer::class => $this->withRecordedReply($value, $builder),
            GatheringPartCompletion::class => self::withRecordedPartReports($value),
            GatheringMessageHandler::class => self::withRecordedGathering($value),
            default => $value,
        };
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array<int|string, mixed>
     */
    private function withRecordedRoutes(array $arguments): array
    {
        $routingKeyResolver = $arguments[0] instanceof Definition ? ($arguments[0]->getArguments()[1] ?? null) : null;
        $routingKeyHeader = $routingKeyResolver instanceof Definition && $routingKeyResolver->getClassName() === BusRoutingKeyResolver::class ? (string) $routingKeyResolver->getArguments()[0] : '';
        $arguments[0] = new Definition(RecordedRouteSelector::class, [$arguments[0], $routingKeyHeader, new Reference(Tracer::class)]);

        return $arguments;
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array<int|string, mixed>
     */
    private function withRecordedRouterRoutes(array $arguments): array
    {
        $arguments[1] = new Definition(RecordedRouteSelector::class, [$arguments[1], '', new Reference(Tracer::class)]);

        return $arguments;
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private static function roleOfProcessor(array $arguments, ?string $role): ?string
    {
        if ($role !== null && $role !== InterceptedChainDefinition::HANDLER) {
            return $role;
        }
        $resultConverter = $arguments[1] ?? null;

        return match ($resultConverter instanceof Definition ? $resultConverter->getClassName() : '') {
            TransformerResultToMessageConverter::class => self::TRANSFORMER,
            OrchestratorResultMessageConverter::class => self::ORCHESTRATOR,
            default => $role,
        };
    }

    private static function withRecordedPartReports(Definition $partCompletion): Definition
    {
        $arguments = $partCompletion->getArguments();
        $arguments[0] = new Definition(RecordedPartReports::class, [$arguments[0], new Reference(Tracer::class)]);
        $partCompletion->replaceArguments($arguments);

        return $partCompletion;
    }

    private static function withRecordedGathering(Definition $gatheringHandler): Definition
    {
        $arguments = $gatheringHandler->getArguments();
        $arguments[0] = new Definition(RecordedGatheredRelease::class, [$arguments[0], new Reference(Tracer::class)]);
        $gatheringHandler->replaceArguments($arguments);

        return new Definition(RecordedGatheringPart::class, [$gatheringHandler, new Reference(Tracer::class)]);
    }

    private function withRecordedReply(Definition $requestReplyProducer, ContainerBuilder $builder): Definition
    {
        $recording = $builder->register(self::REPLY_RECORDING_PREFIX . spl_object_id($requestReplyProducer), new Definition(ReplyRecording::class, [new Reference(Tracer::class)]));
        $arguments = $requestReplyProducer->getArguments();
        if ($arguments[0] instanceof Reference) {
            $arguments[0] = new Definition(RecordedReplyChannel::class, [$arguments[0], 'output channel ' . ($arguments[0] instanceof ChannelReference ? $arguments[0]->getChannelName() : $arguments[0]->getId()), $recording]);
        }
        $arguments[1] = new Definition(RecordedReplyProcessor::class, [$arguments[1], $recording]);
        $arguments[2] = new Definition(RecordedChannelResolver::class, [$arguments[2], $recording]);
        $arguments[5] = new Definition(RecordedPartCompletion::class, [$arguments[5], $recording]);
        $requestReplyProducer->replaceArguments($arguments);

        return new Definition(RecordedRequestReply::class, [$requestReplyProducer, $recording]);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array<int|string, mixed>
     */
    private function withRecordedInvocation(array $arguments, int $aroundInterceptorsPosition, ?string $role, ContainerBuilder $builder): array
    {
        $aroundInterceptors = $arguments[$aroundInterceptorsPosition] ?? [];
        foreach ($arguments as $position => $argument) {
            if ($position !== $aroundInterceptorsPosition) {
                $arguments[$position] = $this->recorded($argument, null, $builder);
            }
        }
        $recordedInterceptors = [];
        foreach ($aroundInterceptors as $aroundInterceptor) {
            $interceptor = $aroundInterceptor instanceof Definition && $aroundInterceptor->getClassName() === AroundMethodInterceptor::class
                ? $this->classOf($aroundInterceptor->getArguments()[0], $builder) . '::' . $aroundInterceptor->getArguments()[1]
                : null;
            if ($interceptor !== null && self::isFlowTesting($interceptor)) {
                $recordedInterceptors[] = self::recordingInterceptor('recordFlowTesting', $interceptor);
                $recordedInterceptors[] = $this->recorded($aroundInterceptor, null, $builder);
                $recordedInterceptors[] = self::recordingInterceptor('observeFlowTesting', $interceptor);
                continue;
            }
            $reference = $interceptor !== null ? $aroundInterceptor->getArguments()[0] : null;
            if ($reference instanceof Reference && str_starts_with($reference->getId(), self::COLLECTOR_SENDER_PREFIX)) {
                $recordedInterceptors[] = self::recordingInterceptor('recordCollectorSender', substr($reference->getId(), strlen(self::COLLECTOR_SENDER_PREFIX)));
            } elseif ($interceptor !== null) {
                $recordedInterceptors[] = self::recordingInterceptor('recordAround', $interceptor);
            }
            $recordedInterceptors[] = $this->recorded($aroundInterceptor, null, $builder);
        }
        if ($role !== null) {
            $invoked = $this->invokedClassOf($arguments[0] ?? null, $builder) . '::' . ($arguments[1] ?? '');
            $role = $invoked === Bridge::class . '::handle' && $role === InterceptedChainDefinition::HANDLER ? self::BRIDGE : $role;
            $recordedInterceptors[] = self::recordingInterceptor(self::isHousekeeping($invoked) ? 'recordHousekeeping' : 'recordInvocation', $role);
        }
        $arguments[$aroundInterceptorsPosition] = $recordedInterceptors;
        ksort($arguments);

        return $arguments;
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array<int|string, mixed>
     */
    private function withRecordedEntry(array $arguments, ContainerBuilder $builder): array
    {
        $arguments = $this->recorded($arguments, null, $builder);
        $replyConverter = $arguments[2] ?? null;
        $invoked = $replyConverter instanceof Definition ? (string) ($replyConverter->getArguments()[1] ?? '') : '';
        $arguments[3] = new Definition(RecordedEntry::class, [$arguments[3], 'gateway ' . ExecutionRecorder::shortNameOf($invoked), new Reference(Tracer::class)]);

        return $arguments;
    }

    private static function isFlowTesting(string $class): bool
    {
        return str_starts_with($class, self::FLOW_TESTING_NAMESPACE);
    }

    private static function isHousekeeping(string $invoked): bool
    {
        return self::isFlowTesting($invoked) || in_array($invoked, self::POLL_HOUSEKEEPING, true);
    }

    private function invokedClassOf(mixed $objectResolver, ContainerBuilder $builder): string
    {
        return $objectResolver instanceof Definition && $objectResolver->getClassName() === MethodInvokerStaticObjectResolver::class
            ? $this->classOf($objectResolver->getArguments()[0], $builder)
            : '';
    }

    private static function recordingInterceptor(string $method, string $value): Definition
    {
        return new Definition(AroundMethodInterceptor::class, [
            new Reference(ExecutionRecorder::class),
            $method,
            [new Definition(ValueConverter::class, [$value]), new Definition(MethodInvocationConverter::class)],
            true,
        ]);
    }

    private function classOf(mixed $interceptor, ContainerBuilder $builder): string
    {
        return match (true) {
            $interceptor instanceof Definition => $interceptor->getClassName(),
            $interceptor instanceof Reference && $builder->has($interceptor->getId()) && $builder->getDefinition($interceptor->getId()) instanceof Definition && ! class_exists($interceptor->getId()) => $builder->getDefinition($interceptor->getId())->getClassName(),
            $interceptor instanceof Reference => $interceptor->getId(),
            is_object($interceptor) => $interceptor::class,
            default => get_debug_type($interceptor),
        };
    }
}
