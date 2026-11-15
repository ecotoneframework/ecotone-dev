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

use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\LicenceDecider;
use Ecotone\Messaging\Gateway\Converter\SerializerHandlerBuilder;
use Ecotone\Messaging\Handler\Bridge\Bridge;
use Ecotone\Messaging\Handler\Enricher\EnricherBuilder;
use Ecotone\Messaging\Handler\InterceptedEndpoint;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithOutputChannel;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\InterceptorWithPointCut;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Handler\Router\RouterBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\UninterruptibleServiceActivator;
use Ecotone\Messaging\Handler\Splitter\SplitterBuilder;
use Ecotone\Messaging\Handler\Transformer\TransformerBuilder;

final class MessageFlowLedgerDiscovery
{
    private const GENERATED_ID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/';
    private const BUILDERS = [
        MessageProcessorActivatorBuilder::class => 'service activator',
        UninterruptibleServiceActivator::class => 'uninterruptible service activator',
        SplitterBuilder::class => 'splitter',
        RouterBuilder::class => 'router',
        TransformerBuilder::class => 'transformer',
        EnricherBuilder::class => 'enricher',
        SerializerHandlerBuilder::class => 'serializer',
    ];

    /** @var array<string, array<string, scalar>> kind and name => entry */
    private array $entries = [];
    /** @var array<string, string> generated endpoint id => name that stays the same across builds */
    private array $stableNames = [];
    /** @var array<string, string> endpoint id => name of its handler entry */
    private array $handlerNames = [];

    private function __construct(
        private readonly Configuration $configuration,
        private readonly InterfaceToCallRegistry $interfaceToCallRegistry,
    ) {
    }

    /**
     * @return array{ledger: list<array<string, scalar>>, handlerNames: array<string, string>}
     */
    public static function discover(Configuration $configuration, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        return (new self($configuration, $interfaceToCallRegistry))->run();
    }

    /**
     * @return array{ledger: list<array<string, scalar>>, handlerNames: array<string, string>}
     */
    private function run(): array
    {
        $handlers = $this->configuration->getRegisteredMessageHandlers();
        foreach ($handlers as $handler) {
            $invoked = $this->invokedInterfaceOf($handler);
            if ($invoked !== null && $this->isGenerated((string) $handler->getEndpointId())) {
                $this->stableNames[(string) $handler->getEndpointId()] = $invoked->getInterfaceName() . '::' . $invoked->getMethodName();
            }
        }
        foreach ($handlers as $handler) {
            $this->handler($handler);
        }
        foreach ($this->configuration->getRegisteredGateways() as $gateway) {
            $name = $gateway->getInterfaceName() . '::' . $gateway->getRelatedMethodName();
            $this->entry(Configuration::GATEWAY, $name, $this->configuration->getRegisteringModule(Configuration::GATEWAY, $name), ['requestChannel' => $this->stable($gateway->getRequestChannelName())]);
        }
        foreach ($this->configuration->getRegisteredMessageChannels() as $channel) {
            $this->entry(Configuration::CHANNEL, $this->stable($channel->getMessageChannelName()), $this->configuration->getRegisteringModule(Configuration::CHANNEL, $channel->getMessageChannelName()), [
                'builder' => $this->shortName($channel::class),
                'pollable' => $channel->isPollable(),
            ]);
        }
        foreach ($this->configuration->getRegisteredConsumers() as $consumer) {
            $this->entry(Configuration::CONSUMER, $this->stable($consumer->getEndpointId()), $this->configuration->getRegisteringModule(Configuration::CONSUMER, $consumer->getEndpointId()), ['builder' => 'inbound channel adapter']);
        }
        foreach ($this->configuration->getRegisteredMethodInterceptors() as $position => $interceptors) {
            foreach ($interceptors as $interceptor) {
                $this->interceptor($position, $interceptor);
            }
        }
        foreach ($this->configuration->getRegisteredServiceDefinitions() as $id => $definition) {
            $this->entry(Configuration::SERVICE, $this->stable($id), $this->configuration->getRegisteringModule(Configuration::SERVICE, $id), match (true) {
                ! $definition instanceof Definition => ['aliasOf' => $this->stable((string) $definition)],
                $definition->hasFactory() && $definition->getFactory() === [LicenceDecider::class, 'decide'] => ['class' => $definition->getClassName(), 'enterprise' => (string) $definition->getArguments()[2], 'openCore' => (string) $definition->getArguments()[1]],
                default => ['class' => $definition->getClassName()],
            });
        }
        foreach ($this->configuration->getRegisteredConsoleCommands() as $consoleCommand) {
            $this->entry(Configuration::CONSOLE_COMMAND, $consoleCommand->getName(), $this->configuration->getRegisteringModule(Configuration::CONSOLE_COMMAND, $consoleCommand->getName()), ['inputChannel' => $this->stable($consoleCommand->getChannelName())]);
        }
        uasort($this->entries, fn (array $first, array $second): int => [$first['module'], $first['kind'], $first['name']] <=> [$second['module'], $second['kind'], $second['name']]);

        return ['ledger' => array_values($this->entries), 'handlerNames' => $this->handlerNames];
    }

    private function handler(MessageHandlerBuilder $handler): void
    {
        $invoked = $this->invokedInterfaceOf($handler);
        $endpointId = (string) $handler->getEndpointId();
        $inputChannel = $this->stable($handler->getInputMessageChannelName());
        $target = $invoked === null ? null : $invoked->getInterfaceName() . '::' . $invoked->getMethodName();
        $builder = match (true) {
            $invoked?->hasMethodAnnotation(Gathered::class) => 'gatherer',
            $invoked?->hasMethodAnnotation(Collected::class) => 'collector',
            $invoked?->getInterfaceName() === Bridge::class => 'bridge',
            default => self::BUILDERS[$handler::class] ?? 'unrecognised builder ' . $handler::class,
        };
        $outputChannel = match (true) {
            $handler instanceof MessageHandlerBuilderWithOutputChannel, $handler instanceof UninterruptibleServiceActivator => (string) $handler->getOutputMessageChannelName(),
            default => '',
        };
        $this->handlerNames[$endpointId] = $this->entry(
            Configuration::HANDLER,
            match (true) {
                ! $this->isGenerated($endpointId) => $endpointId,
                $target !== null && str_contains($inputChannel, $target) => $target,
                default => ($target ?? $builder) . ' on ' . $inputChannel,
            },
            $this->configuration->getRegisteringModule(Configuration::HANDLER, $endpointId),
            array_filter(['builder' => $builder, 'inputChannel' => $inputChannel, 'outputChannel' => $this->stable($outputChannel), 'target' => $target], fn (?string $value): bool => $value !== null && $value !== ''),
        );
    }

    private function interceptor(string $position, InterceptorWithPointCut $interceptor): void
    {
        $target = match (true) {
            $interceptor instanceof AroundInterceptorBuilder => $interceptor->getInterceptingInterface()->getInterfaceName() . '::' . $interceptor->getInterceptingInterface()->getMethodName(),
            $interceptor instanceof MethodInterceptorBuilder => $interceptor->getInterceptingInterfaceReference()->getClassName() . '::' . $interceptor->getInterceptingInterfaceReference()->getMethodName(),
            default => $interceptor::class,
        };
        $this->entry(Configuration::INTERCEPTOR, $position . ' ' . $target, $this->configuration->getRegisteringModule(Configuration::INTERCEPTOR, (string) spl_object_id($interceptor)), ['position' => $position, 'target' => $target]);
    }

    /**
     * @param array<string, scalar> $details
     */
    private function entry(string $kind, string $name, string $module, array $details): string
    {
        $uniqueName = $name;
        for ($occurrence = 2; isset($this->entries[$kind . ':' . $uniqueName]); $occurrence++) {
            $uniqueName = $name . '#' . $occurrence;
        }
        $this->entries[$kind . ':' . $uniqueName] = ['module' => $module, 'kind' => $kind, 'name' => $uniqueName, ...$details];

        return $uniqueName;
    }

    private function invokedInterfaceOf(MessageHandlerBuilder $handler): ?InterfaceToCall
    {
        return match (true) {
            $handler instanceof InterceptedEndpoint => $handler->getInterceptedInterface($this->interfaceToCallRegistry),
            $handler instanceof UninterruptibleServiceActivator => $handler->getInvokedInterface($this->interfaceToCallRegistry),
            default => null,
        };
    }

    private function stable(string $name): string
    {
        return (string) preg_replace(self::GENERATED_ID, '{generated}', strtr($name, $this->stableNames));
    }

    private function isGenerated(string $endpointId): bool
    {
        return $endpointId === '' || preg_match(self::GENERATED_ID, $endpointId) === 1;
    }

    private function shortName(string $class): string
    {
        $segments = explode('\\', $class);

        return end($segments);
    }
}
