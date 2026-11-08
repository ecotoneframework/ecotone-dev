<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Deduplication;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Messaging\Attribute\IdentifiedAnnotation;
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
final class DeduplicationPulseFeatures
{
    /**
     * @param object[] $serviceExtensions
     * @return PulseFeature[]
     */
    public static function reportedFor(array $serviceExtensions, DbalConfiguration $dbalConfiguration, AnnotationFinder $annotationFinder): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }

        $storage = $dbalConfiguration->deduplicationInMemory ? 'in memory' : 'database, connection ' . $dbalConfiguration->getDeduplicationConnectionReference();
        $keptFor = PulseFeature::durationOf($dbalConfiguration->minimumTimeToRemoveMessageInMilliseconds);
        $handlers = [];
        foreach ($annotationFinder->findAnnotatedMethods(Deduplicated::class) as $annotatedMethod) {
            $deduplicated = $annotatedMethod->getAnnotationForMethod();
            $handlers[] = PulseFeature::handlerOf($annotatedMethod) . match (true) {
                $deduplicated->hasExpression() => ' (by expression)',
                $deduplicated->deduplicationHeaderName !== '' => ' (by header ' . $deduplicated->deduplicationHeaderName . ')',
                default => ' (by message id)',
            };
        }

        return [new PulseFeature(
            package: ModulePackageList::DBAL_PACKAGE,
            name: 'Deduplication',
            enabled: $dbalConfiguration->deduplicatedEnabled,
            settings: ['Storage' => $storage, 'Expiration time' => $keptFor, 'Removal batch size' => (string) $dbalConfiguration->deduplicationRemovalBatchSize],
            handlers: $handlers,
            hint: $dbalConfiguration->deduplicatedEnabled
                ? sprintf('A message an asynchronous endpoint has already handled that arrives again with the same message id, such as a broker redelivery or a resent outbox message, is skipped instead of being handled twice. Handled message ids are kept (%s) for %s, then removed. A handler with #[Deduplicated] is deduplicated too, by the header or expression it names, whichever way it is called.', $storage, $keptFor)
                : 'Asynchronous endpoints do not check whether a message was handled before, so a redelivered message is handled again. A handler with #[Deduplicated] is still deduplicated, by the header or expression it names.',
            configuration: 'DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: true)',
        )];
    }

    /**
     * @param object[] $serviceExtensions
     * @return PulseConsumerFeature[]
     */
    public static function consumerFeaturesFor(array $serviceExtensions, DbalConfiguration $dbalConfiguration, AnnotationFinder $annotationFinder): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }
        $features = [PulseConsumerFeature::global('Deduplication', $dbalConfiguration->deduplicatedEnabled ? 'by message id' : 'off')];
        foreach ($annotationFinder->findAnnotatedMethods(Deduplicated::class) as $annotatedMethod) {
            if (! $annotatedMethod->hasMethodAnnotation(IdentifiedAnnotation::class)) {
                continue;
            }
            $deduplicated = $annotatedMethod->getAnnotationForMethod();
            $features[] = PulseConsumerFeature::forConsumer($annotatedMethod->getMethodAnnotationsWithType(IdentifiedAnnotation::class)[0]->getEndpointId(), 'Deduplication', match (true) {
                $deduplicated->hasExpression() => 'by expression',
                $deduplicated->deduplicationHeaderName !== '' => 'by header ' . $deduplicated->deduplicationHeaderName,
                default => 'by message id',
            });
        }

        return $features;
    }
}
