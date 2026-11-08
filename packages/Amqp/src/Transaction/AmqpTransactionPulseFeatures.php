<?php

declare(strict_types=1);

namespace Ecotone\Amqp\Transaction;

use Ecotone\Amqp\Configuration\AmqpConfiguration;
use Ecotone\Api\Amqp\AmqpConnectionReference;
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
final class AmqpTransactionPulseFeatures
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
        $amqpConfiguration = ExtensionObjectResolver::resolveUnique(AmqpConfiguration::class, $serviceExtensions, AmqpConfiguration::createWithDefaults());
        $connections = implode(', ', $amqpConfiguration->getDefaultConnectionReferenceNames() ?: [AmqpConnectionReference::DEFAULT]);
        $entryPoints = array_keys(array_filter([
            'command sent through the command bus' => $amqpConfiguration->isTransactionOnCommandBus(),
            'message an asynchronous endpoint consumes' => $amqpConfiguration->isTransactionOnAsynchronousEndpoints(),
            'console command' => $amqpConfiguration->isTransactionOnConsoleCommands(),
        ]));

        return [new PulseFeature(
            package: ModulePackageList::AMQP_PACKAGE,
            name: 'RabbitMQ transactions',
            enabled: $entryPoints !== [],
            settings: [
                'Command bus' => PulseFeature::onOff($amqpConfiguration->isTransactionOnCommandBus()),
                'Asynchronous endpoints' => PulseFeature::onOff($amqpConfiguration->isTransactionOnAsynchronousEndpoints()),
                'Console commands' => PulseFeature::onOff($amqpConfiguration->isTransactionOnConsoleCommands()),
                'Connections' => $connections,
            ],
            handlers: [],
            hint: $entryPoints === []
                ? 'No entry point opens a RabbitMQ transaction, so a message a handler publishes to RabbitMQ is sent at once, even when the handler fails after publishing it.'
                : sprintf('Each %s runs inside a RabbitMQ transaction on %s: the messages its handler publishes to RabbitMQ are sent when it succeeds, and none of them when it fails.', implode(', each ', $entryPoints), $connections),
            configuration: 'AmqpConfiguration::createWithDefaults()->withTransactionOnCommandBus(true)->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnConsoleCommands(true)',
        )];
    }
}
