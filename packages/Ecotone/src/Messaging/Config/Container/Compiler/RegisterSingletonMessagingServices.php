<?php

namespace Ecotone\Messaging\Config\Container\Compiler;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\ChannelResolverWithContainer;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\Container\ReferenceSearchServiceWithContainer;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\MessagingSystemContainer;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerContext;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerContextProvider;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerErrorInterceptor;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerPostSendAroundInterceptor;
use Ecotone\Messaging\Handler\Bridge\Bridge;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\SymfonyExpressionEvaluationAdapter;
use Ecotone\Messaging\Scheduling\Clock;
use Ecotone\Messaging\Scheduling\EpochBasedClock;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class RegisterSingletonMessagingServices implements CompilerPass
{

    public function process(ContainerBuilder $builder): void
    {
        $this->registerDefault($builder, Bridge::class, new Definition(Bridge::class));
        $this->registerDefault($builder, Clock::class, new Definition(EpochBasedClock::class));
        $this->registerDefault($builder, ChannelResolver::class, new Definition(ChannelResolverWithContainer::class, [new Reference(ContainerInterface::class)]));
        $this->registerDefault($builder, ReferenceSearchService::class, new Definition(ReferenceSearchServiceWithContainer::class, [new Reference(ContainerInterface::class)]));
        $this->registerDefault($builder, ExpressionEvaluationService::REFERENCE, new Definition(SymfonyExpressionEvaluationAdapter::class, [new Reference(ReferenceSearchService::class)], 'create'));
        $this->registerDefault($builder, PollingConsumerContext::class, new Definition(PollingConsumerContextProvider::class, [new Reference(Clock::class), new Reference(LoggerInterface::class), new Reference(ContainerInterface::class)]));
        $this->registerDefault($builder, PollingConsumerPostSendAroundInterceptor::class, [new Reference(PollingConsumerContext::class)]);
        $this->registerDefault($builder, PollingConsumerErrorInterceptor::class, [new Reference(ChannelResolver::class)]);
        $this->registerDefault($builder, ConfiguredMessagingSystem::class, new Definition(MessagingSystemContainer::class, [new Reference(ContainerInterface::class), []]));
        $this->registerDefault($builder, ServiceCacheConfiguration::class, new Definition(ServiceCacheConfiguration::class, factory: 'noCache'));
    }

    private function registerDefault(ContainerBuilder $builder, string $id, object|array|string $definition): void
    {
        if (!$builder->has($id)) {
            $builder->register($id, $definition);
        }
    }
}