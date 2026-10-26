<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\OpenTelemetry\OpenTelemetryConfiguration;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Tracing\TracingConfiguration;

final class EcotoneConfiguration
{
    #[ServiceContext]
    public function service(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()
            ->withServiceName('ticketing')
            ->withDefaultErrorChannel('dbal_dead_letter');
    }

    #[ServiceContext]
    public function database(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
            ->withDoctrineORMRepositories(true)
            ->withDeadLetter(true);
    }

    #[ServiceContext]
    public function distribution(): DistributedServiceMap
    {
        return DistributedServiceMap::initialize()
            ->withCommandMapping(targetServiceName: 'storefront', channelName: 'storefront')
            ->withEventMapping(channelName: 'storefront', subscriptionKeys: ['concert.*', 'tickets.*']);
    }

    #[ServiceContext]
    public function pulse(): PulseConfiguration
    {
        return PulseConfiguration::createWithDefaults(pulseServiceName: 'ecotone_pulse');
    }

    #[ServiceContext]
    public function flowTracking(): FlowTrackingConfiguration
    {
        return FlowTrackingConfiguration::createWithDefaults();
    }

    #[ServiceContext]
    public function dynamicConsistencyBoundary(): DynamicConsistencyBoundaryConfiguration
    {
        return DynamicConsistencyBoundaryConfiguration::createWithDefaults()
            ->withFilterOnlyTags(['concert']);
    }

    #[ServiceContext]
    public function instantRetry(): InstantRetryConfiguration
    {
        return InstantRetryConfiguration::createWithDefaults()
            ->withCommandBusRetry(isEnabled: true, retryTimes: 3, retryExceptions: [DecisionModelConcurrencyException::class]);
    }

    #[ServiceContext]
    public function openTelemetry(): OpenTelemetryConfiguration
    {
        return OpenTelemetryConfiguration::createWithDefaults();
    }

    #[ServiceContext]
    public function tracing(): TracingConfiguration
    {
        return TracingConfiguration::createWithDefaults();
    }
}
