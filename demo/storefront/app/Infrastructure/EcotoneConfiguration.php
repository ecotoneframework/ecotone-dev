<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Doctrine\DBAL\Exception\RetryableException;
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
            ->withServiceName('storefront')
            ->withDefaultErrorChannel('dbal_dead_letter');
    }

    #[ServiceContext]
    public function database(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
            ->withDeadLetter(true);
    }

    #[ServiceContext]
    public function instantRetries(): InstantRetryConfiguration
    {
        return InstantRetryConfiguration::createWithDefaults()
            ->withAsynchronousEndpointsRetry(isEnabled: true, retryTimes: 3, retryExceptions: [RetryableException::class]);
    }

    #[ServiceContext]
    public function distribution(): DistributedServiceMap
    {
        return DistributedServiceMap::initialize()
            ->withCommandMapping(targetServiceName: 'ticketing', channelName: 'ticketing');
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
