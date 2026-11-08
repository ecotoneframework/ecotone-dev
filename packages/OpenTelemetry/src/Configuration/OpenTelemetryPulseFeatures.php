<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry\Configuration;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\OpenTelemetry\OpenTelemetryConfiguration;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;

/**
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
 *
 * @internal
 */
final class OpenTelemetryPulseFeatures
{
    /**
     * @param object[] $serviceExtensions
     * @return PulseFeature[]
     */
    public static function reportedFor(array $serviceExtensions, bool $tracing): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }
        $configuration = ExtensionObjectResolver::resolveUnique(OpenTelemetryConfiguration::class, $serviceExtensions, OpenTelemetryConfiguration::createWithDefaults());

        return [new PulseFeature(
            package: ModulePackageList::TRACING_PACKAGE,
            name: 'OpenTelemetry tracing',
            enabled: $tracing,
            settings: $tracing ? [
                'Flush after a bus call' => PulseFeature::onOff($configuration->forceFlushOnBusExecution),
                'Flush after an asynchronous message' => PulseFeature::onOff($configuration->forceFlushOnAsynchronousMessageHandled),
            ] : [],
            handlers: [],
            hint: $tracing
                ? 'Every message this service sends and handles becomes a span of its trace, exported through the TracerProviderInterface of the container to your collector.' . ($configuration->forceFlushOnBusExecution || $configuration->forceFlushOnAsynchronousMessageHandled ? ' Spans are flushed as soon as the work listed in the settings ends, instead of when the exporter batches them.' : ' Spans leave when the exporter batches them.')
                : 'No TracerProviderInterface is registered in the container, so no span is exported.',
            configuration: 'OpenTelemetryConfiguration::createWithDefaults()->withForceFlushOnBusExecution(true)->withForceFlushOnAsynchronousMessageHandled(true)',
        )];
    }
}
