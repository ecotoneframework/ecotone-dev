<?php

declare(strict_types=1);

namespace Ecotone\Sqs\Configuration;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Sqs\SqsMessagePublisherConfiguration;
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
final class SqsMessagePublisherPulseFeatures
{
    /**
     * @param object[] $serviceExtensions
     * @return PulseFeature[]
     */
    public static function reportedFor(array $serviceExtensions): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }

        return [PulseFeature::messagePublishers(
            package: ModulePackageList::SQS_PACKAGE,
            broker: 'SQS',
            publishers: array_map(
                static fn (SqsMessagePublisherConfiguration $publisher): string => sprintf("%s: queue '%s'%s", PulseFeature::shortNameOf($publisher->getReferenceName()), $publisher->getQueueName(), $publisher->isBatchPublishingEnabled() ? ', batch publishing' : ''),
                ExtensionObjectResolver::resolve(SqsMessagePublisherConfiguration::class, $serviceExtensions),
            ),
            configuration: "SqsMessagePublisherConfiguration::create(MessagePublisher::class, queueName: 'orders')",
        )];
    }
}
