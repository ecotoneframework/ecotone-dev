<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Kafka\KafkaMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\DestinationDoesNotExistException;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;

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
 */

#[RunTestsInSeparateProcesses]
final class RequiredExistingDestinationTest extends ConnectionTestCase
{
    public function test_sending_to_a_missing_topic_is_refused_naming_the_channel_and_the_actual_topic(): void
    {
        $topic = 'renamed_' . Uuid::v7()->toRfc4122();
        $ecotone = $this->bootstrap($topic, true);

        $this->expectException(DestinationDoesNotExistException::class);
        $this->expectExceptionMessage(sprintf(
            "Channel 'required_orders' requires its destination to exist, but topic '%s' does not exist on Kafka broker configuration '%s', so nothing was sent. Run the consumer of this channel (ecotone:run required_orders) or create the topic yourself, or fix the topic name (KafkaMessageChannelBuilder::create('required_orders', topicName: ...)). To let this channel create the topic on send, remove withRequiredExistingDestination().",
            $topic,
            \Ecotone\Api\Kafka\KafkaBrokerConfiguration::class,
        ));

        $ecotone->getCommandBus()->sendWithRouting('place_order', 'order-1');
    }

    public function test_the_refused_send_does_not_create_the_topic_even_when_the_broker_would_auto_create_it(): void
    {
        $ecotone = $this->bootstrap(Uuid::v7()->toRfc4122(), true);

        foreach ([1, 2] as $attempt) {
            try {
                $ecotone->getCommandBus()->sendWithRouting('place_order', 'order-' . $attempt);
                self::fail('Send to a missing topic should be refused');
            } catch (ConfigurationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_sending_works_once_the_topic_has_been_created_by_its_owner(): void
    {
        $topic = Uuid::v7()->toRfc4122();
        $owner = $this->bootstrap($topic, false);
        $sender = $this->bootstrap($topic, true);
        $consumer = $this->bootstrap($topic, false);

        $owner->getCommandBus()->sendWithRouting('place_order', 'order-0');
        $sender->getCommandBus()->sendWithRouting('place_order', 'order-1');
        $consumer->run('required_orders', ExecutionPollingMetadata::createWithTestingSetup(executionTimeLimitInMilliseconds: 6000));

        self::assertContains('order-1', $consumer->getQueryBus()->sendWithRouting('placed_orders'));
    }

    public function test_the_channel_sends_to_a_topic_the_broker_creates_on_demand_by_default(): void
    {
        $ecotone = $this->bootstrap(Uuid::v7()->toRfc4122(), false);

        $ecotone->getCommandBus()->sendWithRouting('place_order', 'order-1');
        $ecotone->run('required_orders', ExecutionPollingMetadata::createWithTestingSetup(executionTimeLimitInMilliseconds: 4000));

        self::assertSame(['order-1'], $ecotone->getQueryBus()->sendWithRouting('placed_orders'));
    }

    private function bootstrap(string $topic, bool $requireExisting): FlowTestSupport
    {
        $builder = KafkaMessageChannelBuilder::create('required_orders', topicName: $topic, messageGroupId: $topic);
        if ($requireExisting) {
            $builder = $builder->withRequiredExistingDestination();
        }

        $handler = new class () {
            public array $orders = [];

            #[Asynchronous('required_orders')]
            #[CommandHandler('place_order', endpointId: 'place_order_handler')]
            public function place(string $orderId): void
            {
                $this->orders[] = $orderId;
            }

            #[QueryHandler('placed_orders')]
            public function placed(): array
            {
                return $this->orders;
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler::class => $handler, \Ecotone\Api\Kafka\KafkaBrokerConfiguration::class => self::getConnection()],
            extensionObjects: [$builder],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
