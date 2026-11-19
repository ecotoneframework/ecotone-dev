<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
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
use Ecotone\Messaging\Config\LicenceDecider;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\AcknowledgeConfirmationInterceptor;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Handler\Bridge\Bridge;
use Ecotone\Messaging\Handler\Gateway\Gateway;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupStore;
use Ecotone\Messaging\Handler\Gathering\GatheringMessageHandler;
use Ecotone\Messaging\Handler\Gathering\GatheringPartCompletion;
use Ecotone\Messaging\Handler\MessageProcessor;
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
use Ecotone\Messaging\Handler\Router\HeaderRouter;
use Ecotone\Messaging\Handler\Router\InvocationRouter;
use Ecotone\Messaging\Handler\Router\Router;
use Ecotone\Messaging\Handler\Router\RouterProcessor;
use Ecotone\Messaging\Handler\Transformer\TransformerResultToMessageConverter;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\AggregateFlow\LoadAggregate\LoadAggregateMessageProcessor;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\SaveAggregateService;
use Ecotone\Modelling\AggregateIdentifierRetrevingService;
use Ecotone\Modelling\Config\InstantRetry\InstantRetryInterceptor;
use Ecotone\Modelling\Config\Routing\BusRoutingKeyResolver;
use Ecotone\Modelling\EventSourcingExecutor\ApplyMethodExecutor;
use Ecotone\Modelling\MessageHandling\Distribution\DistributedOutboundRouter;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Ecotone\Modelling\Repository\AllAggregateRepository;
use Ecotone\Projecting\PartitionBatchDispatcher;
use Ecotone\Projecting\ProjectingManager;
use Ecotone\Projecting\ProjectionStateStorageRegistry;
use Ecotone\Projecting\StreamSourceRegistry;
use Ecotone\Tracing\Tracer;
use ReflectionMethod;

/**
 * licence Apache-2.0
 */
final class RecordExecutionCompilerPass implements CompilerPass
{
    public const PRESEND = 'before send';
    public const CHAINED = 'chained';
    public const ROUTER = 'router';
    public const TRANSFORMER = 'transformer';
    public const SPLITTER = 'splitter';
    public const BRIDGE = 'bridge';
    public const ORCHESTRATOR = 'orchestrator';
    private const OWN_ROLES = [self::TRANSFORMER, self::SPLITTER, self::ORCHESTRATOR];
    private const SPLITTER_PROCESSOR_PREFIX = 'ecotone.gathering.splitter.core.';
    private const CHANNEL_ID_PREFIX = 'channel-';
    private const ENDPOINT_RUNNER_PREFIX = 'endpointRunner.';
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
    /** @var list<Definition> */
    private array $licensed = [];

    public function process(ContainerBuilder $builder): void
    {
        $this->visited = [];
        $this->licensed = [];
        if ($builder->has(AllAggregateRepository::class)) {
            $builder->replace(AllAggregateRepository::class, new Definition(RecordedAggregateRepository::class, [$builder->getDefinition(AllAggregateRepository::class), new Reference(Tracer::class)]));
        }
        if ($builder->has(MessagingEntrypointService::class) && $builder->getDefinition(MessagingEntrypointService::class) instanceof Definition) {
            $builder->replace(MessagingEntrypointService::class, new Definition(RecordedMessagingEntrypointService::class, [...$builder->getDefinition(MessagingEntrypointService::class)->getArguments(), new Reference(Tracer::class)]));
        }
        if ($builder->has(ConversionService::REFERENCE_NAME)) {
            $builder->replace(ConversionService::REFERENCE_NAME, new Definition(RecordedConversionService::class, [$builder->getDefinition(ConversionService::REFERENCE_NAME), new Reference(Tracer::class)]));
        }
        if ($builder->has(PartitionBatchDispatcher::class)) {
            $builder->replace(PartitionBatchDispatcher::class, new Definition(RecordedPartitionBatchDispatcher::class, [$builder->getDefinition(PartitionBatchDispatcher::class), new Reference(Tracer::class)]));
        }
        if ($builder->has(AcknowledgeConfirmationInterceptor::class)) {
            $builder->replace(AcknowledgeConfirmationInterceptor::class, new Definition(RecordedAcknowledgeConfirmationInterceptor::class, [...$builder->getDefinition(AcknowledgeConfirmationInterceptor::class)->getArguments(), new Reference(MessageBuilder::class), new Reference(Tracer::class)]));
        }
        if ($builder->has(GatheredGroupStore::class)) {
            $builder->replace(GatheredGroupStore::class, new Definition(RecordedGatheredGroupStore::class, [$builder->getDefinition(GatheredGroupStore::class), new Reference(Tracer::class)]));
        }
        foreach ($builder->getDefinitions() as $id => $definition) {
            if ($definition instanceof Definition && str_starts_with($id, self::CHANNEL_ID_PREFIX)) {
                $definition = $this->recordChannel(channelName: substr($id, strlen(self::CHANNEL_ID_PREFIX)), channel: $definition, builder: $builder);
                $builder->replace($id, $definition);
            }
            $recorded = $this->recorded(value: $definition, role: str_starts_with($id, self::SPLITTER_PROCESSOR_PREFIX) ? self::SPLITTER : null, builder: $builder);
            if ($recorded !== $definition) {
                $builder->replace($id, $recorded);
            }
        }
        foreach ($builder->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, self::ENDPOINT_RUNNER_PREFIX)) {
                $builder->replace($id, new Definition(RecordedEndpointRunner::class, [$definition, new Reference(Tracer::class)]));
            }
        }
        foreach ($this->licensed as $licensed) {
            $licensed->replaceArguments([$licensed->getArguments()[0], self::frameworkClassesChosenBy($licensed->getArguments()[0], $builder), new Reference(Tracer::class)]);
        }
    }

    /**
     * @return array<string, array{class: string, chosenBy: string}>
     */
    private static function frameworkClassesChosenBy(Definition $licenceChoice, ContainerBuilder $builder): array
    {
        $candidates = [];
        foreach (['open core' => $licenceChoice->getArguments()[1], 'enterprise' => $licenceChoice->getArguments()[2]] as $kind => $reference) {
            $chosen = is_string($reference) && $builder->has($reference) ? $builder->getDefinition($reference) : null;
            if (! $chosen instanceof Definition) {
                continue;
            }
            $inner = $chosen->getArguments()[0] ?? null;
            $candidates[$kind] = [$chosen->getClassName(), str_starts_with($chosen->getClassName(), __NAMESPACE__ . '\\Recorded') && $inner instanceof Definition ? $inner->getClassName() : $chosen->getClassName()];
        }
        $classes = [];
        foreach ($candidates as $kind => [$runtime, $framework]) {
            $other = $candidates[$kind === 'enterprise' ? 'open core' : 'enterprise'][1] ?? null;
            $classes[$runtime] = ['class' => $framework, 'chosenBy' => ($kind === 'enterprise' ? 'licence' : 'no licence') . ($other === null ? '' : ', over ' . ExecutionRecordingTracer::shortClassOf($other))];
        }

        return $classes;
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
                array_map(self::declaredPhasesOf(...), $interceptorClasses),
                array_values(array_filter($interceptorClasses, fn (string $class): bool => ! self::isFlowTesting($class))) === [],
                $channel->getClassName() === PollableChannelInterceptorAdapter::class,
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

    /**
     * @return list<string>
     */
    private static function declaredPhasesOf(string $interceptorClass): array
    {
        if (! class_exists($interceptorClass)) {
            return ChannelSendRecording::PHASES;
        }

        return array_values(array_filter(
            ChannelSendRecording::PHASES,
            fn (string $phase): bool => (new ReflectionMethod($interceptorClass, $phase))->getDeclaringClass()->getName() !== AbstractChannelInterceptor::class,
        ));
    }

    private function recorded(mixed $value, ?string $role, ContainerBuilder $builder): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $element) {
                $value[$key] = $this->recorded(value: $element, role: $role, builder: $builder);
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
                $chainRole = $chainRole === InterceptedChainDefinition::STEP ? self::CHAINED : $chainRole;
                $arguments[0][$position] = $this->recorded(value: $processor, role: $chainRole === InterceptedChainDefinition::HANDLER && in_array($role, self::OWN_ROLES, true) ? $role : $chainRole, builder: $builder);
            }
        } else {
            $arguments = match ($value->getClassName()) {
                MethodInvoker::class => $this->withRecordedInvocation(arguments: $arguments, aroundInterceptorsPosition: 4, role: $role ?? InterceptedChainDefinition::HANDLER, builder: $builder),
                AroundMessageProcessor::class => $this->withRecordedInvocation(arguments: $arguments, aroundInterceptorsPosition: 2, role: null, builder: $builder),
                Gateway::class => $this->withRecordedEntry($arguments, $builder),
                BeforeSendChannelInterceptor::class => $this->recorded(value: $arguments, role: self::PRESEND, builder: $builder),
                InvocationRouter::class => $this->recorded(value: $arguments, role: self::ROUTER, builder: $builder),
                RouterProcessor::class => $this->withRecordedRoutes($this->recorded(value: $arguments, role: null, builder: $builder)),
                Router::class => $this->withRecordedRouterRoutes($this->recorded(value: $arguments, role: null, builder: $builder)),
                MethodInvokerProcessor::class => $this->recorded(value: $arguments, role: self::roleOfProcessor($arguments, $role), builder: $builder),
                default => $this->recorded(value: $arguments, role: $role, builder: $builder),
            };
        }
        $value->replaceArguments($arguments);
        foreach ($value->getMethodCalls() as $methodCall) {
            $methodCall->replaceArguments($this->recorded(value: $methodCall->getArguments(), role: null, builder: $builder));
        }

        if ($value->hasFactory() && $value->getFactory() === [LicenceDecider::class, 'decide'] && in_array($value->getClassName(), [MessageProcessor::class, MessageHandler::class], true)) {
            $licensed = new Definition($value->getClassName() === MessageProcessor::class ? RecordedLicensedMessageProcessor::class : RecordedLicensedMessageHandler::class, [$value, [], new Reference(Tracer::class)]);
            $this->licensed[] = $licensed;

            return $licensed;
        }

        return match ($value->getClassName()) {
            Gateway::class => self::recordedGateway($value),
            RequestReplyProducer::class => $this->withRecordedReply($value, $builder),
            GatheringPartCompletion::class => self::withRecordedPartReports($value),
            GatheringMessageHandler::class => self::withRecordedGathering($value),
            AggregateIdentifierRetrevingService::class => self::recordedAggregateStep($value, RecordedAggregateStep::IDENTIFIER),
            LoadAggregateMessageProcessor::class => self::recordedAggregateStep($value, RecordedAggregateStep::LOAD),
            SaveAggregateService::class => self::recordedAggregateStep($value, RecordedAggregateStep::SAVE),
            ApplyMethodExecutor::class => self::withRecordedApply($value),
            ProjectionStateStorageRegistry::class => self::withEachRecordedAs($value, RecordedProjectionStateStorage::class),
            StreamSourceRegistry::class => self::withEachRecordedAs($value, RecordedStreamSource::class),
            ProjectingManager::class => self::withRecordedProjectorExecutor($value),
            InstantRetryInterceptor::class => new Definition(RecordedInstantRetryInterceptor::class, [...array_slice(array_pad($value->getArguments(), 5, null), 0, 5), new Reference(Tracer::class)]),
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
        $arguments[0] = new Definition(RecordedRouteSelector::class, [$arguments[0], $routingKeyHeader, self::routeHeaderOf($arguments[0]), new Reference(Tracer::class)]);

        return $arguments;
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array<int|string, mixed>
     */
    private function withRecordedRouterRoutes(array $arguments): array
    {
        $invoked = $arguments[1] instanceof Definition && $arguments[1]->getClassName() === InvocationRouter::class ? ($arguments[1]->getArguments()[1] ?? '') : '';
        $arguments[1] = new Definition(RecordedRouteSelector::class, [$arguments[1], '', $invoked === DistributedOutboundRouter::class . '::route' ? RecordedRouteSelector::SERVICE_MAP : self::routeHeaderOf($arguments[1]), new Reference(Tracer::class)]);

        return $arguments;
    }

    private static function routeHeaderOf(mixed $routeSelector, int $depth = 8): string
    {
        if (is_array($routeSelector) && $depth > 0) {
            foreach ($routeSelector as $element) {
                $header = self::routeHeaderOf($element, $depth - 1);
                if ($header !== '') {
                    return $header;
                }
            }

            return '';
        }
        if (! $routeSelector instanceof Definition) {
            return '';
        }
        if ($routeSelector->getClassName() === HeaderRouter::class) {
            return (string) $routeSelector->getArguments()[0];
        }

        return $depth > 0 ? self::routeHeaderOf($routeSelector->getArguments(), $depth - 1) : '';
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

    private static function withEachRecordedAs(Definition $registry, string $recordedClass): Definition
    {
        $registry->replaceArguments(array_map(
            fn (mixed $services): mixed => is_array($services) ? array_map(fn (mixed $service): Definition => new Definition($recordedClass, [$service, new Reference(Tracer::class)]), $services) : $services,
            $registry->getArguments(),
        ));

        return $registry;
    }

    private static function withRecordedProjectorExecutor(Definition $projectingManager): Definition
    {
        $arguments = $projectingManager->getArguments();
        $arguments[1] = new Definition(RecordedProjectorExecutor::class, [$arguments[1], new Reference(Tracer::class)]);
        $projectingManager->replaceArguments($arguments);

        return $projectingManager;
    }

    private static function recordedGateway(Definition $gateway): Definition
    {
        $arguments = $gateway->getArguments();
        $arguments[3] = new Definition(RecordedGatewayRequest::class, [$arguments[3], new Reference(Tracer::class)]);
        $recorded = new Definition(RecordedGateway::class, $arguments);
        foreach ($gateway->getMethodCalls() as $methodCall) {
            $recorded->addMethodCall($methodCall->getMethodName(), $methodCall->getArguments());
        }

        return $recorded;
    }

    private static function recordedAggregateStep(Definition $processor, string $step): Definition
    {
        return new Definition(RecordedAggregateStep::class, [$processor, $step, new Reference(Tracer::class)]);
    }

    private static function withRecordedApply(Definition $applyMethodExecutor): Definition
    {
        $arguments = $applyMethodExecutor->getArguments();
        $arguments[2] = new Definition(RecordedAggregateMethodInvoker::class, [$arguments[2], new Reference(Tracer::class)]);
        $applyMethodExecutor->replaceArguments($arguments);

        return $applyMethodExecutor;
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

    private static function handlerNamed(string $interface, mixed $processor): string
    {
        if (! str_contains($interface, '::')) {
            return match ($processor instanceof Definition ? $processor->getClassName() : '') {
                RouterProcessor::class => 'the router',
                default => 'the chain',
            };
        }
        [$class, $method] = explode('::', $interface, 2);

        return ExecutionRecordingTracer::shortClassOf($class) . '::' . $method;
    }

    private function withRecordedReply(Definition $requestReplyProducer, ContainerBuilder $builder): Definition
    {
        $arguments = $requestReplyProducer->getArguments();
        $recording = $builder->register(self::REPLY_RECORDING_PREFIX . spl_object_id($requestReplyProducer), new Definition(ReplyRecording::class, [self::handlerNamed((string) ($arguments[6] ?? ''), $arguments[1]), new Reference(Tracer::class)]));
        if ($arguments[0] instanceof Reference) {
            $arguments[0] = new Definition(RecordedReplyChannel::class, [$arguments[0], ReplyRecording::OUTPUT_CHANNEL, $arguments[0] instanceof ChannelReference ? $arguments[0]->getChannelName() : $arguments[0]->getId(), $recording]);
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
                $arguments[$position] = $this->recorded(value: $argument, role: null, builder: $builder);
            }
        }
        if ($role !== null && is_array($arguments[2] ?? null) && is_array($arguments[3] ?? null)) {
            foreach ($arguments[2] as $position => $parameterConverter) {
                $arguments[2][$position] = new Definition(RecordedParameterConverter::class, [$parameterConverter, (string) ($arguments[3][$position] ?? $position), new Reference(Tracer::class)]);
            }
        }
        $recordedInterceptors = [];
        foreach ($aroundInterceptors as $aroundInterceptor) {
            $interceptor = $aroundInterceptor instanceof Definition && $aroundInterceptor->getClassName() === AroundMethodInterceptor::class
                ? $this->classOf($aroundInterceptor->getArguments()[0], $builder) . '::' . $aroundInterceptor->getArguments()[1]
                : null;
            if ($interceptor !== null && self::isFlowTesting($interceptor)) {
                $recordedInterceptors[] = self::recordingInterceptor('recordFlowTesting', $interceptor);
                $recordedInterceptors[] = $this->recorded(value: $aroundInterceptor, role: null, builder: $builder);
                $recordedInterceptors[] = self::recordingInterceptor('observeFlowTesting', $interceptor);
                continue;
            }
            $reference = $interceptor !== null ? $aroundInterceptor->getArguments()[0] : null;
            if ($reference instanceof Reference && str_starts_with($reference->getId(), self::COLLECTOR_SENDER_PREFIX)) {
                $recordedInterceptors[] = self::recordingInterceptor('recordCollectorSender', substr($reference->getId(), strlen(self::COLLECTOR_SENDER_PREFIX)));
            } elseif ($interceptor !== null) {
                $recordedInterceptors[] = self::recordingInterceptor('recordAround', $interceptor);
            }
            $recordedInterceptors[] = $this->recorded(value: $aroundInterceptor, role: null, builder: $builder);
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
        $arguments = $this->recorded(value: $arguments, role: null, builder: $builder);
        $replyConverter = $arguments[2] ?? null;
        $invoked = $replyConverter instanceof Definition ? (string) ($replyConverter->getArguments()[1] ?? '') : '';

        return [...array_values($arguments), 'gateway ' . ExecutionRecorder::shortNameOf($invoked), new Reference(Tracer::class)];
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
        $class = $this->recordedClassOf($interceptor, $builder);

        return $class === RecordedInstantRetryInterceptor::class ? InstantRetryInterceptor::class : $class;
    }

    private function recordedClassOf(mixed $interceptor, ContainerBuilder $builder): string
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
