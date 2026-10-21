<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Kafka\KafkaMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

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
final class PulseReplyChannelTest extends TestCase
{
    public function test_a_kafka_request_channel_cannot_derive_the_reply_channel_and_names_the_builder_to_register(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Pulse replies go to channel 'ecotone_pulse', which is not a registered message channel, and the builder of request channel 'order_service' cannot be re-created for another channel name. Register 'ecotone_pulse' explicitly, e.g. AmqpBackedMessageChannelBuilder::create('ecotone_pulse') (or the DbalBackedMessageChannelBuilder, RedisBackedMessageChannelBuilder, SqsBackedMessageChannelBuilder or KafkaMessageChannelBuilder::create('ecotone_pulse') your other channels use), or send the replies to another registered channel with PulseConfiguration::withReplyChannel().");

        EcotoneLite::bootstrapFlowTesting(
            extensionObjects: [
                KafkaMessageChannelBuilder::create('order_service'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
