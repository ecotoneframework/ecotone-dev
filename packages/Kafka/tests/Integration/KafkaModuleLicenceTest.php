<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Kafka\KafkaConsumer;
use Ecotone\Api\Kafka\KafkaMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Support\LicensingException;
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
final class KafkaModuleLicenceTest extends TestCase
{
    public function test_an_installed_kafka_module_that_nothing_uses_needs_no_licence(): void
    {
        $orders = new class () {
            private array $placed = [];

            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
                $this->placed[] = $order;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                return $this->placed;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
        );

        self::assertSame(['coffee'], $ecotone->sendCommandWithRouting('order.place', 'coffee')->sendQueryWithRouting('order.placed'));
    }

    public function test_a_kafka_channel_without_a_licence_is_refused_naming_the_channel_and_the_licence_key(): void
    {
        try {
            EcotoneLite::bootstrapFlowTesting(extensionObjects: [KafkaMessageChannelBuilder::create('orders')], enabledModules: [ModulePackageList::KAFKA_PACKAGE]);
            self::fail('A Kafka channel without a licence was accepted');
        } catch (LicensingException $exception) {
            self::assertStringContainsString('Kafka is used here (KafkaMessageChannelBuilder for channel `orders`)', $exception->getMessage());
            self::assertStringContainsString("licenceKey in your framework's Ecotone configuration", $exception->getMessage());
            self::assertStringContainsString("EcotoneLite::bootstrapFlowTesting(..., licenceKey: '...')", $exception->getMessage());
        }
    }

    public function test_a_kafka_consumer_without_a_licence_is_refused_naming_the_consumer(): void
    {
        $consumer = new class () {
            #[KafkaConsumer('orders_consumer', 'orders')]
            public function consume(string $payload): void
            {
            }
        };

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Kafka is used here (#[KafkaConsumer] `orders_consumer`)');

        EcotoneLite::bootstrapFlowTesting(
            [$consumer::class],
            [$consumer],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
        );
    }
}
