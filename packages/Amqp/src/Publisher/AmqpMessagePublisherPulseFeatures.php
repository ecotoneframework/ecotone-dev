<?php

declare(strict_types=1);

namespace Ecotone\Amqp\Publisher;

use Ecotone\Api\Amqp\AmqpMessagePublisherConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
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
final class AmqpMessagePublisherPulseFeatures
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
            package: ModulePackageList::AMQP_PACKAGE,
            broker: 'RabbitMQ',
            publishers: array_map(
                static fn (AmqpMessagePublisherConfiguration $publisher): string => sprintf(
                    "%s: exchange '%s'%s%s%s",
                    PulseFeature::shortNameOf($publisher->getReferenceName()),
                    $publisher->getExchangeName(),
                    $publisher->getDefaultRoutingKey() === '' ? '' : sprintf(", routing key '%s'", $publisher->getDefaultRoutingKey()),
                    $publisher->getDefaultPersistentDelivery() ? ', persistent' : '',
                    $publisher->isBatchPublishingEnabled() ? ', batch publishing' : '',
                ),
                ExtensionObjectResolver::resolve(AmqpMessagePublisherConfiguration::class, $serviceExtensions),
            ),
            configuration: "AmqpMessagePublisherConfiguration::create(MessagePublisher::class, exchangeName: 'orders')",
        )];
    }
}
