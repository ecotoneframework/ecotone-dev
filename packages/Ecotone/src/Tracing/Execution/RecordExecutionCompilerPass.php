<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Channel\EventDrivenChannelInterceptorAdapter;
use Ecotone\Messaging\Channel\PollableChannelInterceptorAdapter;
use Ecotone\Messaging\Channel\PublishSubscribeChannel;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\MethodInterceptor\BeforeSendChannelInterceptor;
use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\Processor\InterceptedChainDefinition;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMessageProcessor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInterceptor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\MethodInvocationConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ValueConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\Router\InvocationRouter;
use Ecotone\Tracing\Tracer;

/**
 * licence Apache-2.0
 */
final class RecordExecutionCompilerPass implements CompilerPass
{
    public const PRESEND = 'presend';
    public const ROUTER = 'router';
    public const GATEWAY = 'gateway';
    private const CHANNEL_ID_PREFIX = 'channel-';
    private const SEND_RECORDING_PREFIX = 'ecotone.execution.send.';

    /** @var array<int, true> */
    private array $visited = [];

    public function process(ContainerBuilder $builder): void
    {
        $this->visited = [];
        foreach ($builder->getDefinitions() as $id => $definition) {
            if ($definition instanceof Definition && str_starts_with($id, self::CHANNEL_ID_PREFIX)) {
                $this->recordChannel(substr($id, strlen(self::CHANNEL_ID_PREFIX)), $definition, $builder);
            }
            $this->recorded($definition, null, $builder);
        }
    }

    private function recordChannel(string $channelName, Definition $channel, ContainerBuilder $builder): void
    {
        $arguments = $channel->getArguments();
        $innerChannel = $channel;
        if (in_array($channel->getClassName(), [PollableChannelInterceptorAdapter::class, EventDrivenChannelInterceptorAdapter::class], true)) {
            $innerChannel = $arguments[0];
            $recording = $builder->register(self::SEND_RECORDING_PREFIX . $channelName, new Definition(ChannelSendRecording::class, [
                $channelName,
                array_map(fn (mixed $interceptor): string => $this->classOf($interceptor, $builder), array_values($arguments[1])),
                new Reference(Tracer::class),
            ]));
            $interceptors = [new Definition(ChannelSendStarted::class, [$recording])];
            foreach (array_values($arguments[1]) as $position => $interceptor) {
                $interceptors[] = $interceptor;
                $interceptors[] = new Definition(ChannelInterceptorRan::class, [$recording, $position]);
            }
            $arguments[1] = $interceptors;
            $channel->replaceArguments($arguments);
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
                $arguments[0][$position] = $this->recorded($processor, $value->roles[$position] ?? InterceptedChainDefinition::STEP, $builder);
            }
        } else {
            $arguments = match ($value->getClassName()) {
                MethodInvoker::class => $this->withRecordedInvocation($arguments, 4, $role ?? InterceptedChainDefinition::HANDLER, $builder),
                AroundMessageProcessor::class => $this->withRecordedInvocation($arguments, 2, self::GATEWAY, $builder),
                BeforeSendChannelInterceptor::class => $this->recorded($arguments, self::PRESEND, $builder),
                InvocationRouter::class => $this->recorded($arguments, self::ROUTER, $builder),
                default => $this->recorded($arguments, $role, $builder),
            };
        }
        $value->replaceArguments($arguments);
        foreach ($value->getMethodCalls() as $methodCall) {
            $methodCall->replaceArguments($this->recorded($methodCall->getArguments(), null, $builder));
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array<int|string, mixed>
     */
    private function withRecordedInvocation(array $arguments, int $aroundInterceptorsPosition, string $role, ContainerBuilder $builder): array
    {
        $aroundInterceptors = $arguments[$aroundInterceptorsPosition] ?? [];
        foreach ($arguments as $position => $argument) {
            if ($position !== $aroundInterceptorsPosition) {
                $arguments[$position] = $this->recorded($argument, null, $builder);
            }
        }
        $recordedInterceptors = [];
        foreach ($aroundInterceptors as $aroundInterceptor) {
            if ($aroundInterceptor instanceof Definition && $aroundInterceptor->getClassName() === AroundMethodInterceptor::class) {
                $recordedInterceptors[] = self::recordingInterceptor('recordAround', $this->classOf($aroundInterceptor->getArguments()[0], $builder) . '::' . $aroundInterceptor->getArguments()[1]);
            }
            $recordedInterceptors[] = $this->recorded($aroundInterceptor, null, $builder);
        }
        $recordedInterceptors[] = self::recordingInterceptor('recordInvocation', $role);
        $arguments[$aroundInterceptorsPosition] = $recordedInterceptors;
        ksort($arguments);

        return $arguments;
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
