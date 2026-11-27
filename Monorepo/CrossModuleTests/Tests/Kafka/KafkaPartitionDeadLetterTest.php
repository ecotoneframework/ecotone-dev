<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\Kafka;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\PartitionDeadLetter;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Api\Kafka\KafkaMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Modelling\WithEvents;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class KafkaPartitionDeadLetterTest extends TestCase
{
    private object $notifications;

    public function test_a_failed_aggregate_is_held_in_the_dead_letter_while_the_other_aggregates_of_its_kafka_topic_are_handled(): void
    {
        $ecotone = $this->bootstrap();
        $ecotone->sendCommandWithRouting('kafkaPartition.order.place', 'broken-1');
        $ecotone->sendCommandWithRouting('kafkaPartition.order.place', 'healthy-2');
        $ecotone->sendCommandWithRouting('kafkaPartition.order.ship', metadata: ['aggregate.id' => 'broken-1']);
        $ecotone->sendCommandWithRouting('kafkaPartition.order.ship', metadata: ['aggregate.id' => 'healthy-2']);

        $this->consume($ecotone, 4);

        self::assertSame(['healthy-2 placed', 'healthy-2 shipped'], $this->notifications->notified);
        self::assertSame(2, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_a_held_aggregate_is_replayed_through_its_kafka_topic_in_the_order_its_events_failed(): void
    {
        $ecotone = $this->bootstrap();
        $ecotone->sendCommandWithRouting('kafkaPartition.order.place', 'broken-1');
        $ecotone->sendCommandWithRouting('kafkaPartition.order.ship', metadata: ['aggregate.id' => 'broken-1']);
        $this->consume($ecotone, 2);

        $this->notifications->broken = false;
        $held = $ecotone->getGateway(DeadLetterGateway::class)->list(100, 0);
        usort($held, static fn (ErrorContext $first, ErrorContext $second): int => $second->partitionPosition <=> $first->partitionPosition);
        $ecotone->getGateway(DeadLetterGateway::class)->replay($held[0]->getMessageId());
        $this->consume($ecotone, 2);

        self::assertSame(['broken-1 placed', 'broken-1 shipped'], $this->notifications->notified);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    private function consume(FlowTestSupport $ecotone, int $messages): void
    {
        $ecotone->run('kafkaPartition.notifications', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: $messages, executionTimeLimitInMilliseconds: 15000, stopOnError: false));
    }

    private function bootstrap(): FlowTestSupport
    {
        $this->notifications = new class () {
            public array $notified = [];
            public bool $broken = true;

            #[PartitionDeadLetter]
            #[Asynchronous('kafkaPartition.notifications')]
            #[EventHandler(endpointId: 'kafkaPartition.notifyAboutOrder')]
            public function notifyAboutOrder(KafkaPartitionedOrderChanged $event): void
            {
                if ($this->broken && str_starts_with($event->orderId, 'broken') && $event->change === 'placed') {
                    throw new RuntimeException('notifications are down');
                }

                $this->notified[] = "{$event->orderId} {$event->change}";
            }
        };
        $connectionFactory = new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@database:5432/ecotone');

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$this->notifications::class, KafkaPartitionedOrder::class],
            [
                $this->notifications,
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
                DbalConnectionFactory::class => $connectionFactory,
            ],
            extensionObjects: [
                KafkaMessageChannelBuilder::create('kafkaPartition.notifications', topicName: 'kafka_partition_dead_letter_' . Uuid::v7()->toRfc4122()),
                DbalConfiguration::createWithDefaults(),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'),
            ],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE, ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->runConsoleCommand('ecotone:migration:database:delete', ['force' => true]);
        $ecotone->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);

        return $ecotone;
    }
}

final class KafkaPartitionedOrderChanged
{
    public function __construct(public readonly string $orderId, public readonly string $change)
    {
    }
}

#[Aggregate]
final class KafkaPartitionedOrder
{
    use WithEvents;

    #[Identifier]
    private string $orderId;

    #[CommandHandler('kafkaPartition.order.place')]
    public static function place(string $orderId): self
    {
        $order = new self();
        $order->orderId = $orderId;
        $order->recordThat(new KafkaPartitionedOrderChanged($orderId, 'placed'));

        return $order;
    }

    #[CommandHandler('kafkaPartition.order.ship')]
    public function ship(): void
    {
        $this->recordThat(new KafkaPartitionedOrderChanged($this->orderId, 'shipped'));
    }
}
