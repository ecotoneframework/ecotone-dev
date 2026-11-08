<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\Api\Attribute\Poller;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseConsumerFeature;
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
final class ScheduledPulseFeatures
{
    /**
     * @param object[] $serviceExtensions
     * @param AnnotatedFinding[] $scheduledRegistrations
     * @return PulseFeature[]
     */
    public static function reportedFor(array $serviceExtensions, array $scheduledRegistrations): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }
        $pollingMetadata = [];
        foreach (ExtensionObjectResolver::resolve(PollingMetadata::class, $serviceExtensions) as $metadata) {
            $pollingMetadata[$metadata->getEndpointId()] = $metadata;
        }
        $handlers = [];
        foreach ($scheduledRegistrations as $registration) {
            $endpointId = $registration->getAnnotationForMethod()->getEndpointId();
            $handlers[] = $endpointId . ': ' . self::scheduleOf($pollingMetadata[$endpointId] ?? ($registration->hasMethodAnnotation(Poller::class) ? $registration->getMethodAnnotationsWithType(Poller::class)[0] : null));
        }

        return [new PulseFeature(
            package: ModulePackageList::CORE_PACKAGE,
            name: 'Scheduled endpoints',
            enabled: $handlers !== [],
            settings: $handlers === [] ? [] : ['Scheduled endpoints' => (string) count($handlers)],
            handlers: $handlers,
            hint: $handlers === []
                ? 'No method is marked #[Scheduled], so nothing runs on a schedule of its own.'
                : 'Each endpoint listed here runs on its own schedule while its consumer is started (ecotone:run with the endpoint id), and sends what it returns to its request channel. #[Poller] next to #[Scheduled] sets the rate or the cron expression; without it the endpoint runs every second.',
            configuration: "#[Scheduled('report.send', 'reports')] with #[Poller(cron: '0 6 * * *')]",
        )];
    }

    /**
     * @param object[] $serviceExtensions
     * @param PollingMetadata[] $pollerMetadata
     * @return PulseConsumerFeature[]
     */
    public static function pollerFeaturesFor(array $serviceExtensions, array $pollerMetadata): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }
        $byEndpoint = [];
        foreach ([...$pollerMetadata, ...ExtensionObjectResolver::resolve(PollingMetadata::class, $serviceExtensions)] as $metadata) {
            $byEndpoint[$metadata->getEndpointId()] = PulseConsumerFeature::forConsumer($metadata->getEndpointId(), 'Poller', self::scheduleOf($metadata));
        }

        return array_values($byEndpoint);
    }

    public static function scheduleOf(PollingMetadata|Poller|null $schedule): string
    {
        return match (true) {
            $schedule === null => 'every ' . PulseFeature::durationOf(PollingMetadata::DEFAULT_FIXED_RATE),
            $schedule instanceof Poller && $schedule->getCron() !== '' => 'cron ' . $schedule->getCron(),
            $schedule instanceof Poller && $schedule->getCronExpression() !== null => 'cron expression ' . $schedule->getCronExpression(),
            $schedule instanceof Poller && $schedule->getFixedRateExpression() !== null => 'rate expression ' . $schedule->getFixedRateExpression(),
            $schedule instanceof Poller => 'every ' . PulseFeature::durationOf($schedule->getFixedRateInMilliseconds()),
            $schedule->cron !== '' => 'cron ' . $schedule->cron,
            $schedule->cronExpression !== null => 'cron expression ' . $schedule->cronExpression,
            $schedule->fixedRateExpression !== null => 'rate expression ' . $schedule->fixedRateExpression,
            default => 'every ' . PulseFeature::durationOf($schedule->fixedRateInMilliseconds),
        };
    }
}
