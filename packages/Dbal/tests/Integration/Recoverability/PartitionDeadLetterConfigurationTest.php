<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Recoverability;

use DateTimeImmutable;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\PartitionDeadLetter;
use Ecotone\Api\Attribute\Saga;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Api\Modelling\WithEvents;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use Ecotone\Test\StaticMessageBuilder;
use InvalidArgumentException;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class PartitionDeadLetterConfigurationTest extends DbalMessagingTestCase
{
    private const HOW_TO_ADD_THE_PARTITION_COLUMNS =
        "The 'ecotone_error_messages' table has no 'partition_tracking_name', 'partition_key' and 'partition_position' columns, which holding partition keys in the dead letter (#[PartitionDeadLetter]) writes and reads. The table was created before these columns were part of it. Ecotone never alters a table while it handles a message.\n\n"
        . "Add the columns with the statement for your database:\n"
        . "  PostgreSQL: ALTER TABLE ecotone_error_messages ADD COLUMN partition_tracking_name VARCHAR(255) DEFAULT NULL; ALTER TABLE ecotone_error_messages ADD COLUMN partition_key VARCHAR(255) DEFAULT NULL; ALTER TABLE ecotone_error_messages ADD COLUMN partition_position BIGINT DEFAULT NULL; CREATE INDEX ecotone_error_messages_partition_idx ON ecotone_error_messages (partition_tracking_name, partition_key, partition_position);\n"
        . "  MySQL and MariaDB: ALTER TABLE ecotone_error_messages ADD COLUMN partition_tracking_name VARCHAR(255) DEFAULT NULL, ADD COLUMN partition_key VARCHAR(255) DEFAULT NULL, ADD COLUMN partition_position BIGINT DEFAULT NULL, ADD INDEX ecotone_error_messages_partition_idx (partition_tracking_name, partition_key, partition_position);\n"
        . "  SQLite: ALTER TABLE ecotone_error_messages ADD COLUMN partition_tracking_name VARCHAR(255) DEFAULT NULL; ALTER TABLE ecotone_error_messages ADD COLUMN partition_key VARCHAR(255) DEFAULT NULL; ALTER TABLE ecotone_error_messages ADD COLUMN partition_position BIGINT DEFAULT NULL; CREATE INDEX ecotone_error_messages_partition_idx ON ecotone_error_messages (partition_tracking_name, partition_key, partition_position);\n\n"
        . 'Or stop holding partition keys: remove #[PartitionDeadLetter].';

    public function test_an_event_listener_class_holds_the_events_of_a_failed_aggregate_and_not_those_of_another_aggregate_with_the_same_identifier(): void
    {
        $notifications = new #[PartitionDeadLetter] class () {
            public array $notified = [];
            public array $broken = [PartitionedOrderChanged::class];

            #[Asynchronous('partitionConfiguration.notifications')]
            #[EventHandler(endpointId: 'partitionConfiguration.notifyAboutOrder')]
            public function notifyAboutOrder(PartitionedOrderChanged $event): void
            {
                if (in_array($event::class, $this->broken, true)) {
                    throw new RuntimeException('notifications are down');
                }

                $this->notified[] = "order {$event->orderId} {$event->change}";
            }

            #[Asynchronous('partitionConfiguration.notifications')]
            #[EventHandler(endpointId: 'partitionConfiguration.notifyAboutInvoice')]
            public function notifyAboutInvoice(PartitionedInvoiceIssued $event): void
            {
                $this->notified[] = "invoice {$event->invoiceId} issued";
            }
        };
        $ecotone = $this->bootstrap(
            [$notifications::class, PartitionedOrder::class, PartitionedInvoice::class],
            [$notifications],
        );

        $ecotone->sendCommandWithRouting('partitionConfiguration.order.place', '1');
        $this->consume($ecotone, 'partitionConfiguration.notifications');
        $notifications->broken = [];
        $ecotone->sendCommandWithRouting('partitionConfiguration.order.ship', metadata: ['aggregate.id' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.invoice.issue', '1');
        $ecotone->sendCommandWithRouting('partitionConfiguration.order.place', '2');
        $this->consume($ecotone, 'partitionConfiguration.notifications');

        self::assertSame(['invoice 1 issued', 'order 2 placed'], $notifications->notified);
        self::assertSame(2, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_an_aggregate_holds_every_asynchronous_command_of_the_instance_whose_command_failed(): void
    {
        $ecotone = $this->bootstrap([PartitionedOrder::class], []);
        $ecotone->sendCommandWithRouting('partitionConfiguration.order.place', '1');
        $ecotone->sendCommandWithRouting('partitionConfiguration.order.place', '2');

        $ecotone->sendCommandWithRouting('partitionConfiguration.order.pay', 'declined', metadata: ['aggregate.id' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.order.cancel', metadata: ['aggregate.id' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.order.cancel', metadata: ['aggregate.id' => '2']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        self::assertSame(['placed'], $ecotone->getAggregate(PartitionedOrder::class, '1')->changes);
        self::assertSame(['placed', 'cancelled'], $ecotone->getAggregate(PartitionedOrder::class, '2')->changes);
        self::assertSame(PartitionedOrder::class, $this->held($ecotone)[0]->partitionTrackingName);
    }

    public function test_a_message_of_another_handler_of_the_same_class_follows_the_dead_lettered_partition_key(): void
    {
        $handlers = new class () {
            public array $handled = [];

            #[PartitionDeadLetter(partitionHeaderName: 'orderId')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.place', endpointId: 'partitionConfiguration.place.endpoint')]
            public function place(string $item): void
            {
                throw new RuntimeException("cannot place {$item}");
            }

            #[PartitionDeadLetter(partitionHeaderName: 'orderId')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.pay', endpointId: 'partitionConfiguration.pay.endpoint')]
            public function pay(string $payment): void
            {
                $this->handled[] = "paid {$payment}";
            }
        };
        $ecotone = $this->bootstrap([$handlers::class], [$handlers]);

        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'broken', metadata: ['orderId' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.pay', 'card', metadata: ['orderId' => '1']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        self::assertSame([], $handlers->handled);
        self::assertSame($handlers::class, $this->held($ecotone)[0]->partitionTrackingName);
    }

    public function test_a_handler_does_not_hold_the_partition_key_of_a_handler_of_another_class(): void
    {
        $charging = new class () {
            #[PartitionDeadLetter(partitionHeaderName: 'orderId')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.charge', endpointId: 'partitionConfiguration.charge.endpoint')]
            public function charge(string $item): void
            {
                throw new RuntimeException('charging is down');
            }
        };
        $packing = new class () {
            public array $handled = [];

            #[PartitionDeadLetter(partitionHeaderName: 'orderId')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.pack', endpointId: 'partitionConfiguration.pack.endpoint')]
            public function pack(string $item): void
            {
                $this->handled[] = "packed {$item}";
            }
        };
        $ecotone = $this->bootstrap([$charging::class, $packing::class], [$charging, $packing]);

        $ecotone->sendCommandWithRouting('partitionConfiguration.charge', 'coffee', metadata: ['orderId' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.pack', 'coffee', metadata: ['orderId' => '1']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        self::assertSame(['packed coffee'], $packing->handled);
    }

    public function test_handlers_of_one_class_with_their_own_tracking_names_do_not_hold_the_partition_key_of_each_other(): void
    {
        $handlers = new class () {
            public array $handled = [];

            #[PartitionDeadLetter(partitionHeaderName: 'orderId', trackingName: 'order_charging')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.charge', endpointId: 'partitionConfiguration.charge.endpoint')]
            public function charge(string $item): void
            {
                throw new RuntimeException('charging is down');
            }

            #[PartitionDeadLetter(partitionHeaderName: 'orderId', trackingName: 'order_packing')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.pack', endpointId: 'partitionConfiguration.pack.endpoint')]
            public function pack(string $item): void
            {
                $this->handled[] = "packed {$item}";
            }
        };
        $ecotone = $this->bootstrap([$handlers::class], [$handlers]);

        $ecotone->sendCommandWithRouting('partitionConfiguration.charge', 'coffee', metadata: ['orderId' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.pack', 'coffee', metadata: ['orderId' => '1']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        self::assertSame(['packed coffee'], $handlers->handled);
    }

    public function test_handlers_sharing_a_tracking_name_hold_the_partition_key_together(): void
    {
        $handlers = new class () {
            public array $handled = [];

            #[PartitionDeadLetter(partitionHeaderName: 'orderId', trackingName: 'order_fulfilment')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.charge', endpointId: 'partitionConfiguration.charge.endpoint')]
            public function charge(string $item): void
            {
                throw new RuntimeException('charging is down');
            }

            #[PartitionDeadLetter(partitionHeaderName: 'orderId', trackingName: 'order_fulfilment')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.pack', endpointId: 'partitionConfiguration.pack.endpoint')]
            public function pack(string $item): void
            {
                $this->handled[] = "packed {$item}";
            }
        };
        $ecotone = $this->bootstrap([$handlers::class], [$handlers]);

        $ecotone->sendCommandWithRouting('partitionConfiguration.charge', 'coffee', metadata: ['orderId' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.pack', 'coffee', metadata: ['orderId' => '1']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        self::assertSame([], $handlers->handled);
        self::assertSame('order_fulfilment', $this->held($ecotone)[0]->partitionTrackingName);
    }

    public function test_the_attribute_on_a_class_holds_the_partition_key_for_all_its_handlers_together(): void
    {
        $handlers = new #[PartitionDeadLetter(partitionHeaderName: 'orderId')] class () {
            public array $handled = [];

            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.charge', endpointId: 'partitionConfiguration.charge.endpoint')]
            public function charge(string $item): void
            {
                throw new RuntimeException('charging is down');
            }

            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.pack', endpointId: 'partitionConfiguration.pack.endpoint')]
            public function pack(string $item): void
            {
                $this->handled[] = "packed {$item}";
            }
        };
        $ecotone = $this->bootstrap([$handlers::class], [$handlers]);

        $ecotone->sendCommandWithRouting('partitionConfiguration.charge', 'coffee', metadata: ['orderId' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.pack', 'coffee', metadata: ['orderId' => '1']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        self::assertSame([], $handlers->handled);
    }

    public function test_the_partition_key_is_calculated_by_an_expression(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[PartitionDeadLetter(expression: "headers['tenant'] ~ ':' ~ headers['orderId']")]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.place', endpointId: 'partitionConfiguration.place.endpoint')]
            public function place(string $item, #[Header('tenant')] string $tenant): void
            {
                if ($item === 'broken') {
                    throw new RuntimeException('cannot place broken');
                }

                $this->handled[] = "{$tenant}:{$item}";
            }
        };
        $ecotone = $this->bootstrap([$handler::class], [$handler]);

        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'broken', metadata: ['tenant' => 'a', 'orderId' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'coffee', metadata: ['tenant' => 'a', 'orderId' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'tea', metadata: ['tenant' => 'b', 'orderId' => '1']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        self::assertSame(['b:tea'], $handler->handled);
        self::assertSame('a:1', $this->held($ecotone)[0]->partitionKey);
    }

    public function test_the_partition_key_is_calculated_by_an_expression_over_the_payload_of_a_dbal_channel(): void
    {
        $handler = new class () {
            public array $placed = [];

            #[PartitionDeadLetter(expression: 'payload.orderId')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler(endpointId: 'partitionConfiguration.place.endpoint')]
            public function place(PartitionedPlaceOrder $command): void
            {
                if ($command->item === 'broken') {
                    throw new RuntimeException('cannot place broken');
                }

                $this->placed[] = "{$command->orderId}:{$command->item}";
            }
        };
        $ecotone = $this->bootstrapOnADbalChannel([$handler::class], [$handler], 'partitionConfiguration.orders');

        $ecotone->sendCommand(new PartitionedPlaceOrder('1', 'broken'));
        $ecotone->sendCommand(new PartitionedPlaceOrder('1', 'coffee'));
        $ecotone->sendCommand(new PartitionedPlaceOrder('2', 'tea'));
        $ecotone->run('partitionConfiguration.orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false)->withHandledMessageLimit(10));

        self::assertSame(['2:tea'], $handler->placed);
        self::assertSame('1', $this->held($ecotone)[0]->partitionKey);
    }

    public function test_a_message_whose_partition_key_cannot_be_calculated_is_dead_lettered_without_holding_a_key_and_the_next_ones_are_handled(): void
    {
        $handler = new class () {
            public array $shipped = [];

            #[PartitionDeadLetter(expression: 'payload.partitionKey()')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler(endpointId: 'partitionConfiguration.ship.endpoint')]
            public function ship(PartitionedShipOrder $command): void
            {
                $this->shipped[] = "{$command->orderId}:{$command->item}";
            }
        };
        $ecotone = $this->bootstrapOnADbalChannel([$handler::class], [$handler], 'partitionConfiguration.orders');

        $ecotone->sendCommand(new PartitionedShipOrder('', 'coffee'), metadata: ['id' => 'm-1']);
        $ecotone->sendCommand(new PartitionedShipOrder('2', 'tea'));
        $ecotone->run('partitionConfiguration.orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false)->withHandledMessageLimit(10));

        self::assertSame(['2:tea'], $handler->shipped);
        self::assertSame(1, $ecotone->getGateway(DeadLetterGateway::class)->count());
        self::assertSame([], $this->held($ecotone));
    }

    public function test_a_message_whose_partition_key_cannot_be_calculated_names_the_cause_and_how_to_replay_it(): void
    {
        $handler = new class () {
            #[PartitionDeadLetter(expression: 'payload.partitionKey()')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler(endpointId: 'partitionConfiguration.ship.endpoint')]
            public function ship(PartitionedShipOrder $command): void
            {
            }
        };
        $ecotone = $this->bootstrap([$handler::class], [$handler]);

        $ecotone->sendCommand(new PartitionedShipOrder('', 'coffee'), metadata: ['id' => 'm-1']);
        $this->consume($ecotone, 'partitionConfiguration.orders');
        $error = $ecotone->getGateway(DeadLetterGateway::class)->show('m-1')->getHeaders()->get(ErrorContext::EXCEPTION_MESSAGE);

        self::assertStringStartsWith(
            'The partition key of message `m-1` could not be calculated for `' . $handler::class . '`, so the message was not handled and is stored in the dead letter without holding a partition key. '
            . 'Correct what #[PartitionDeadLetter] takes the partition key from (its expression or partitionHeaderName) or the message, then replay it with DeadLetterGateway::replay(\'m-1\'). Cause: ',
            $error,
        );
        self::assertStringContainsString('the order to ship has no id', $error);
    }

    public function test_a_message_following_a_held_partition_key_is_logged_as_information_naming_the_key_and_the_message_holding_it(): void
    {
        $handler = $this->partitionedHandler(failing: true);
        $logger = $this->collectingLogger();
        $ecotone = $this->bootstrap([$handler::class], [$handler, 'logger' => $logger]);
        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'coffee', metadata: ['orderId' => '1', 'id' => 'm-1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'tea', metadata: ['orderId' => '1', 'id' => 'm-2']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        self::assertContains(
            ['info', 'Message `m-2` follows partition key `1` of `' . $handler::class . '` into the dead letter without being handled: the partition key is held there since message `m-1` failed.'],
            $logger->logged,
        );
        self::assertSame(
            [['error', 'Error occurred during handling message. Sending Message to handle it in predefined Error Channel.']],
            array_values(array_filter($logger->logged, static fn (array $logged): bool => $logged[0] === 'error')),
        );
    }

    public function test_replaying_a_later_message_of_a_held_partition_key_logs_the_message_its_key_is_started_from(): void
    {
        $handler = $this->partitionedHandler(failing: true);
        $logger = $this->collectingLogger();
        $ecotone = $this->bootstrap([$handler::class], [$handler, 'logger' => $logger]);
        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'coffee', metadata: ['orderId' => '1', 'id' => 'm-1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'tea', metadata: ['orderId' => '1', 'id' => 'm-2']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2');

        self::assertContains(
            [
                'info',
                'Replaying dead letter message `m-2` starts with message `m-1`, the oldest one of partition key `1` of `' . $handler::class . '` in the dead letter, so the messages of the partition key are handled in the order they failed. '
                . 'To replay only message `m-2`, out of that order, pass ignorePartitionOrder: true to DeadLetterGateway::replay() or --ignorePartitionOrder to the ecotone:deadletter:replay console command.',
            ],
            $logger->logged,
        );
    }

    public function test_a_replayed_message_that_passes_on_a_delayed_retry_lets_the_next_one_of_its_partition_key_follow(): void
    {
        $handler = new class () {
            public array $handled = [];
            public int $failuresLeft = 2;

            #[PartitionDeadLetter(partitionHeaderName: 'orderId')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.place', endpointId: 'partitionConfiguration.place.endpoint')]
            public function place(string $item): void
            {
                if ($item === 'flaky' && $this->failuresLeft-- > 0) {
                    throw new RuntimeException('cannot place flaky yet');
                }

                $this->handled[] = $item;
            }
        };
        $ecotone = $this->bootstrap(
            [$handler::class],
            [$handler],
            [ErrorHandlerConfiguration::createWithDeadLetterChannel('partitionConfiguration.errors', RetryTemplateBuilder::fixedBackOff(1000)->maxRetries(1), 'dbal_dead_letter')],
            errorChannel: 'partitionConfiguration.errors',
            instantRetries: false,
            initialTime: new DateTimeImmutable('2026-11-25 10:00:00 UTC'),
        );
        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'flaky', metadata: ['orderId' => '2', MessageHeaders::MESSAGE_ID => 'm-2']);
        $this->consume($ecotone, 'partitionConfiguration.orders');
        $ecotone->advanceTimeBy(Duration::seconds(2));
        $this->consume($ecotone, 'partitionConfiguration.orders');
        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'coffee', metadata: ['orderId' => '2']);
        $this->consume($ecotone, 'partitionConfiguration.orders');
        self::assertSame([], $handler->handled);

        $handler->failuresLeft = 1;
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2');
        $this->consume($ecotone, 'partitionConfiguration.orders');
        $ecotone->advanceTimeBy(Duration::seconds(2));
        $this->consume($ecotone, 'partitionConfiguration.orders');

        self::assertSame(['flaky', 'coffee'], $handler->handled);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_a_replayed_message_does_not_pass_its_release_on_to_the_messages_its_handler_publishes(): void
    {
        $handler = new class () {
            public array $publishedHeaders = [];
            public bool $broken = true;

            #[PartitionDeadLetter(partitionHeaderName: 'orderId')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.place', endpointId: 'partitionConfiguration.place.endpoint')]
            public function place(string $item, EventBus $eventBus): void
            {
                if ($this->broken) {
                    throw new RuntimeException('cannot place');
                }

                $eventBus->publishWithRouting('partitionConfiguration.placed', $item);
            }

            #[EventHandler('partitionConfiguration.placed')]
            public function whenPlaced(string $item, #[Headers] array $headers): void
            {
                $this->publishedHeaders = $headers;
            }
        };
        $ecotone = $this->bootstrap([$handler::class], [$handler]);
        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'coffee', metadata: ['orderId' => '1', MessageHeaders::MESSAGE_ID => 'm-1']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        $handler->broken = false;
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->consume($ecotone, 'partitionConfiguration.orders');

        self::assertSame('1', $handler->publishedHeaders['orderId']);
        self::assertSame([], array_values(array_filter(array_keys($handler->publishedHeaders), fn (string $header): bool => str_contains($header, 'deadletter'))));
    }

    public function test_the_attribute_on_a_method_is_used_instead_of_the_one_on_its_class(): void
    {
        $handlers = new #[PartitionDeadLetter(partitionHeaderName: 'customerId')] class () {
            #[PartitionDeadLetter(partitionHeaderName: 'orderId', trackingName: 'order_placing')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.place', endpointId: 'partitionConfiguration.place.endpoint')]
            public function place(string $item): void
            {
                throw new RuntimeException('cannot place');
            }
        };
        $ecotone = $this->bootstrap([$handlers::class], [$handlers]);

        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'coffee', metadata: ['orderId' => '1', 'customerId' => '7']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        $held = $this->held($ecotone)[0];
        self::assertSame(['order_placing', '1'], [$held->partitionTrackingName, $held->partitionKey]);
    }

    public function test_an_aggregate_and_the_listener_of_its_events_hold_their_partition_keys_under_their_own_tracking_names(): void
    {
        $notifications = new #[PartitionDeadLetter] class () {
            #[Asynchronous('partitionConfiguration.notifications')]
            #[EventHandler(endpointId: 'partitionConfiguration.notifyAboutOrder')]
            public function notifyAboutOrder(PartitionedOrderChanged $event): void
            {
                throw new RuntimeException('notifications are down');
            }
        };
        $ecotone = $this->bootstrap(
            [$notifications::class, PartitionedOrder::class],
            [$notifications],
        );

        $ecotone->sendCommandWithRouting('partitionConfiguration.order.place', '1');
        $this->consume($ecotone, 'partitionConfiguration.notifications');
        $ecotone->sendCommandWithRouting('partitionConfiguration.order.pay', 'declined', metadata: ['aggregate.id' => '1']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        self::assertEqualsCanonicalizing(
            [$notifications::class, PartitionedOrder::class],
            array_map(static fn (ErrorContext $held): string => $held->partitionTrackingName, $this->held($ecotone)),
        );
    }

    public function test_the_attribute_on_a_class_leaves_its_synchronous_handlers_as_they_were(): void
    {
        $handlers = new #[PartitionDeadLetter(partitionHeaderName: 'orderId')] class () {
            public array $handled = [];

            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.charge', endpointId: 'partitionConfiguration.charge.endpoint')]
            public function charge(string $item): void
            {
                throw new RuntimeException('cannot charge');
            }

            #[CommandHandler('partitionConfiguration.pack')]
            public function pack(string $item): void
            {
                $this->handled[] = $item;
            }
        };
        $ecotone = $this->bootstrap([$handlers::class], [$handlers]);

        $ecotone->sendCommandWithRouting('partitionConfiguration.charge', 'coffee', metadata: ['orderId' => '1']);
        $this->consume($ecotone, 'partitionConfiguration.orders');
        $ecotone->sendCommandWithRouting('partitionConfiguration.pack', 'coffee', metadata: ['orderId' => '1']);

        self::assertSame(['coffee'], $handlers->handled);
    }

    public function test_a_saga_holds_the_events_of_the_instance_whose_event_failed(): void
    {
        $ecotone = $this->bootstrap([PartitionedShipment::class], []);
        $ecotone->sendCommandWithRouting('partitionConfiguration.shipment.start', '1');
        $ecotone->sendCommandWithRouting('partitionConfiguration.shipment.start', '2');

        $ecotone->publishEvent(new PartitionedOrderChanged('1', 'lost'));
        $ecotone->publishEvent(new PartitionedOrderChanged('1', 'shipped'));
        $ecotone->publishEvent(new PartitionedOrderChanged('2', 'shipped'));
        $this->consume($ecotone, 'partitionConfiguration.shipments');

        self::assertSame([], $ecotone->getSaga(PartitionedShipment::class, '1')->changes);
        self::assertSame(['shipped'], $ecotone->getSaga(PartitionedShipment::class, '2')->changes);
        self::assertSame(2, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_an_event_handler_holds_the_events_of_a_failed_event_sourced_aggregate(): void
    {
        $ledger = new class () {
            public array $recorded = [];

            #[PartitionDeadLetter]
            #[Asynchronous('partitionConfiguration.ledger')]
            #[EventHandler(endpointId: 'partitionConfiguration.recordInLedger')]
            public function record(PartitionedWalletCredited $event): void
            {
                if ($event->amount < 0) {
                    throw new RuntimeException('ledger refuses a negative credit');
                }

                $this->recorded[] = "{$event->walletId}:{$event->amount}";
            }
        };
        $ecotone = $this->bootstrap(
            [$ledger::class, PartitionedWallet::class],
            [$ledger],
        );

        $ecotone->sendCommandWithRouting('partitionConfiguration.wallet.open', '1');
        $ecotone->sendCommandWithRouting('partitionConfiguration.wallet.open', '2');
        $ecotone->sendCommandWithRouting('partitionConfiguration.wallet.credit', -5, metadata: ['aggregate.id' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.wallet.credit', 10, metadata: ['aggregate.id' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.wallet.credit', 20, metadata: ['aggregate.id' => '2']);
        $this->consume($ecotone, 'partitionConfiguration.ledger');

        self::assertSame(['2:20'], $ledger->recorded);
        self::assertSame(PartitionedWallet::class . ':1', $this->held($ecotone)[0]->partitionKey);
    }

    public function test_the_partition_key_named_by_both_a_header_and_an_expression_is_refused(): void
    {
        $handler = new class () {
            #[PartitionDeadLetter(partitionHeaderName: 'orderId', expression: "headers['tenant']")]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.place', endpointId: 'partitionConfiguration.place.endpoint')]
            public function place(string $item): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            '::place takes the partition key from either partitionHeaderName or expression, yet both were given. '
            . 'Keep the one the partition key should come from and remove the other.'
        );

        $this->bootstrap([$handler::class], [$handler]);
    }

    public function test_handlers_of_one_class_taking_the_partition_key_from_different_sources_under_its_default_tracking_name_are_refused(): void
    {
        $handlers = new class () {
            #[PartitionDeadLetter(partitionHeaderName: 'orderId')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.place', endpointId: 'partitionConfiguration.place.endpoint')]
            public function place(string $item): void
            {
            }

            #[PartitionDeadLetter(partitionHeaderName: 'customerId')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.invoice', endpointId: 'partitionConfiguration.invoice.endpoint')]
            public function invoice(string $item): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            $handlers::class . '::place takes the partition key from the header `orderId` and ' . $handlers::class . '::invoice takes it from the header `customerId`, yet both hold it under the tracking name `' . $handlers::class . '`, the default for the handlers of one class, so the same value of two different things would hold the messages of each other. '
            . "Take the partition key from the same source in both handlers, or give each handler its own tracking name with #[PartitionDeadLetter(trackingName: '...')]."
        );

        $this->bootstrap([$handlers::class], [$handlers]);
    }

    public function test_handlers_of_one_class_taking_the_partition_key_from_different_sources_share_a_tracking_name_they_both_name(): void
    {
        $handlers = new class () {
            public array $handled = [];

            #[PartitionDeadLetter(partitionHeaderName: 'orderId', trackingName: 'orders')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.place', endpointId: 'partitionConfiguration.place.endpoint')]
            public function place(string $item): void
            {
                throw new RuntimeException("cannot place {$item}");
            }

            #[PartitionDeadLetter(expression: "headers['order']", trackingName: 'orders')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.pay', endpointId: 'partitionConfiguration.pay.endpoint')]
            public function pay(string $payment): void
            {
                $this->handled[] = "paid {$payment}";
            }
        };
        $ecotone = $this->bootstrap([$handlers::class], [$handlers]);

        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'broken', metadata: ['orderId' => '1']);
        $ecotone->sendCommandWithRouting('partitionConfiguration.pay', 'card', metadata: ['order' => '1']);
        $this->consume($ecotone, 'partitionConfiguration.orders');

        self::assertSame([], $handlers->handled);
    }

    public function test_the_attribute_naming_no_partition_key_is_refused_on_a_handler_that_has_none_by_default(): void
    {
        $handler = new class () {
            #[PartitionDeadLetter]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.place', endpointId: 'partitionConfiguration.place.endpoint')]
            public function place(string $item): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            "::place: it has neither partitionHeaderName nor expression, and the handler is neither a handler of an aggregate (held by the aggregate's identifier) nor an event handler (held by the aggregate that published the event), so no partition key would ever be held. "
            . "Name the header that carries the partition key with #[PartitionDeadLetter(partitionHeaderName: 'orderId')], calculate it with #[PartitionDeadLetter(expression: 'payload.orderId')], or remove #[PartitionDeadLetter]."
        );

        $this->bootstrap([$handler::class], [$handler]);
    }

    public function test_the_attribute_is_refused_on_a_class_without_an_asynchronous_handler(): void
    {
        $handlers = new #[PartitionDeadLetter(partitionHeaderName: 'orderId')] class () {
            #[CommandHandler('partitionConfiguration.place')]
            public function place(string $item): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'which has no asynchronous handler, yet a partition key is held in the dead letter only for a handler consuming a message channel, so no partition key would ever be held. '
            . "Make its handlers asynchronous with #[Asynchronous('channel_name')], or remove #[PartitionDeadLetter] from the class."
        );

        $this->bootstrap([$handlers::class], [$handlers]);
    }

    public function test_the_attribute_is_refused_on_a_projection(): void
    {
        $projection = new #[Projection('partitioned_orders'), FromStream('partitioned_orders_stream'), Asynchronous('partitionConfiguration.projections'), PartitionDeadLetter] class () {
            #[EventHandler]
            public function whenChanged(PartitionedOrderChanged $event): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'yet a projection is not held by partition key: it reads its stream from its own position and stops there when an event fails. '
            . 'Remove #[PartitionDeadLetter] from the projection; to keep a failing aggregate from stopping the others, partition the projection itself with #[Partitioned].'
        );

        $this->bootstrap([$projection::class], [$projection]);
    }

    public function test_holding_a_partition_key_in_a_dead_letter_table_created_by_ecotone_1x_is_refused_naming_how_to_add_the_partition_columns(): void
    {
        $this->createDeadLetterTableAsEcotone1xDid();
        $handler = $this->partitionedHandler(failing: true);
        $ecotone = $this->bootstrap([$handler::class], [$handler], dbalConfiguration: DbalConfiguration::createWithDefaults()->withDeadLetter(true));
        $ecotone->sendCommandWithRouting('partitionConfiguration.place', 'coffee', metadata: ['orderId' => '1']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::HOW_TO_ADD_THE_PARTITION_COLUMNS);

        $ecotone->run('partitionConfiguration.orders', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_listing_the_dead_letters_of_a_table_created_by_ecotone_1x_is_refused_naming_how_to_add_the_partition_columns_once_partition_keys_are_held(): void
    {
        $this->createDeadLetterTableAsEcotone1xDid();
        $handler = $this->partitionedHandler();
        $ecotone = $this->bootstrap([$handler::class], [$handler], dbalConfiguration: DbalConfiguration::createWithDefaults()->withDeadLetter(true));
        $ecotone->getGateway(DeadLetterGateway::class)->store(StaticMessageBuilder::withPayload('failed')->build());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::HOW_TO_ADD_THE_PARTITION_COLUMNS);

        $ecotone->getGateway(DeadLetterGateway::class)->list(100, 0);
    }

    public function test_holding_partition_keys_is_refused_without_an_enterprise_licence(): void
    {
        $handler = $this->partitionedHandler();

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage(
            'Holding the messages of a failed partition key in the dead letter (the #[PartitionDeadLetter] attribute) requires an Ecotone Enterprise licence. '
            . 'Provide your licence key as `licenceKey` in the Symfony, Laravel or Tempest Ecotone configuration, or as the `licenceKey:` argument of EcotoneLite, or start a trial at https://ecotone.tech/pricing. '
            . 'Alternatively, remove #[PartitionDeadLetter].'
        );

        $this->bootstrap([$handler::class], [$handler], licenceKey: null);
    }

    public function test_holding_partition_keys_is_refused_when_the_dead_letter_is_switched_off(): void
    {
        $handler = $this->partitionedHandler();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'The #[PartitionDeadLetter] attribute is used, yet the Dbal dead letter is switched off, so there is nowhere to hold the messages of a failed partition key. '
            . 'Switch it on with DbalConfiguration::createWithDefaults()->withDeadLetter(true), or remove the attribute.'
        );

        $this->bootstrap([$handler::class], [$handler], dbalConfiguration: DbalConfiguration::createWithDefaults()->withDeadLetter(false));
    }

    public function test_the_attribute_is_refused_on_a_handler_that_is_not_asynchronous(): void
    {
        $handler = new class () {
            #[PartitionDeadLetter(partitionHeaderName: 'orderId')]
            #[CommandHandler('partitionConfiguration.place')]
            public function place(string $item): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'which is handled synchronously, yet a partition key is held in the dead letter only for a handler consuming a message channel. '
            . "Make the handler asynchronous with #[Asynchronous('channel_name')], or remove #[PartitionDeadLetter] from it."
        );

        $this->bootstrap([$handler::class], [$handler]);
    }

    public function test_a_handler_whose_failed_messages_do_not_end_in_the_dbal_dead_letter_is_refused(): void
    {
        $handler = $this->partitionedHandler();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'holds failed partition keys in the dead letter, yet its failed messages end in `partitionConfiguration.errors`, not in the Dbal dead letter `dbal_dead_letter`, so a failed partition key would never be held. '
            . "Send them there with ServiceConfiguration::withDefaultErrorChannel('dbal_dead_letter'), with ErrorHandlerConfiguration::createWithDeadLetterChannel('errorChannel', \$retries, 'dbal_dead_letter') as the default error channel's handler, "
            . "or on the handler with #[Asynchronous('partitionConfiguration.orders', asynchronousExecution: [new ErrorChannel('dbal_dead_letter')])]; or remove #[PartitionDeadLetter] from this handler and its class."
        );

        $this->bootstrap([$handler::class], [$handler], errorChannel: 'partitionConfiguration.errors');
    }

    private function createDeadLetterTableAsEcotone1xDid(): void
    {
        $table = new Table('ecotone_error_messages');
        $table->addColumn('message_id', Types::STRING, ['length' => 255]);
        $table->addColumn('failed_at', Types::DATETIME_MUTABLE);
        $table->addColumn('payload', Types::TEXT);
        $table->addColumn('headers', Types::TEXT);
        $table->setPrimaryKey(['message_id']);

        $this->getConnectionFactory()->createContext()->getDbalConnection()->createSchemaManager()->createTable($table);
    }

    private function partitionedHandler(bool $failing = false): object
    {
        return new class ($failing) {
            public function __construct(private bool $failing)
            {
            }

            #[PartitionDeadLetter(partitionHeaderName: 'orderId')]
            #[Asynchronous('partitionConfiguration.orders')]
            #[CommandHandler('partitionConfiguration.place', endpointId: 'partitionConfiguration.place.endpoint')]
            public function place(string $item): void
            {
                if ($this->failing) {
                    throw new RuntimeException('cannot place');
                }
            }
        };
    }

    private function collectingLogger(): AbstractLogger
    {
        return new class () extends AbstractLogger {
            public array $logged = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logged[] = [$level, (string) $message];
            }
        };
    }

    /**
     * @return list<ErrorContext>
     */
    private function held(FlowTestSupport $ecotone): array
    {
        return array_values(array_filter(
            $ecotone->getGateway(DeadLetterGateway::class)->list(100, 0),
            static fn (ErrorContext $errorContext): bool => $errorContext->partitionKey !== null,
        ));
    }

    private function consume(FlowTestSupport $ecotone, string $channel): void
    {
        $ecotone->run($channel, ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
    }

    /**
     * @param list<class-string> $classesToResolve
     * @param list<object> $services
     */
    private function bootstrapOnADbalChannel(array $classesToResolve, array $services, string $channel): FlowTestSupport
    {
        return $this->bootstrap(
            classesToResolve: $classesToResolve,
            services: $services,
            extensionObjects: [DbalBackedMessageChannelBuilder::create($channel)],
            dbalConfiguration: DbalConfiguration::createWithDefaults()->withDeadLetter(true),
        )->setupDatabaseTables();
    }

    /**
     * @param list<class-string> $classesToResolve
     * @param list<object> $services
     * @param list<object> $extensionObjects
     */
    private function bootstrap(
        array $classesToResolve,
        array $services,
        array $extensionObjects = [],
        ?string $licenceKey = LicenceTesting::VALID_LICENCE,
        ?DbalConfiguration $dbalConfiguration = null,
        string $errorChannel = 'dbal_dead_letter',
        bool $instantRetries = true,
        ?DateTimeImmutable $initialTime = null,
    ): FlowTestSupport {
        return $this->bootstrapFlowTesting(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: [...$services, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel($errorChannel),
                $dbalConfiguration ?? DbalConfiguration::createWithDefaults()->withDeadLetter(true, inMemory: true),
                ...($instantRetries ? [] : [InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)]),
                ...$extensionObjects,
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            initialTime: $initialTime,
            licenceKey: $licenceKey,
        );
    }
}

final class PartitionedPlaceOrder
{
    public function __construct(public readonly string $orderId, public readonly string $item)
    {
    }
}

final class PartitionedShipOrder
{
    public function __construct(public readonly string $orderId, public readonly string $item)
    {
    }

    public function partitionKey(): string
    {
        if ($this->orderId === '') {
            throw new InvalidArgumentException('the order to ship has no id');
        }

        return $this->orderId;
    }
}

final class PartitionedOrderChanged
{
    public function __construct(public readonly string $orderId, public readonly string $change)
    {
    }
}

final class PartitionedInvoiceIssued
{
    public function __construct(public readonly string $invoiceId)
    {
    }
}

#[Aggregate]
#[PartitionDeadLetter]
final class PartitionedOrder
{
    use WithEvents;

    public array $changes = [];

    #[Identifier]
    private string $orderId;

    #[CommandHandler('partitionConfiguration.order.place')]
    public static function place(string $orderId): self
    {
        $order = new self();
        $order->orderId = $orderId;
        $order->changes[] = 'placed';
        $order->recordThat(new PartitionedOrderChanged($orderId, 'placed'));

        return $order;
    }

    #[CommandHandler('partitionConfiguration.order.ship')]
    public function ship(): void
    {
        $this->changes[] = 'shipped';
        $this->recordThat(new PartitionedOrderChanged($this->orderId, 'shipped'));
    }

    #[Asynchronous('partitionConfiguration.orders')]
    #[CommandHandler('partitionConfiguration.order.pay', endpointId: 'partitionConfiguration.order.pay.endpoint')]
    public function pay(string $payment): void
    {
        if ($payment === 'declined') {
            throw new RuntimeException('payment declined');
        }

        $this->changes[] = 'paid';
    }

    #[Asynchronous('partitionConfiguration.orders')]
    #[CommandHandler('partitionConfiguration.order.cancel', endpointId: 'partitionConfiguration.order.cancel.endpoint')]
    public function cancel(): void
    {
        $this->changes[] = 'cancelled';
    }
}

#[Aggregate]
final class PartitionedInvoice
{
    use WithEvents;

    #[Identifier]
    private string $invoiceId;

    #[CommandHandler('partitionConfiguration.invoice.issue')]
    public static function issue(string $invoiceId): self
    {
        $invoice = new self();
        $invoice->invoiceId = $invoiceId;
        $invoice->recordThat(new PartitionedInvoiceIssued($invoiceId));

        return $invoice;
    }
}

#[Saga]
#[PartitionDeadLetter]
final class PartitionedShipment
{
    public array $changes = [];

    #[Identifier]
    private string $orderId;

    #[CommandHandler('partitionConfiguration.shipment.start')]
    public static function start(string $orderId): self
    {
        $shipment = new self();
        $shipment->orderId = $orderId;

        return $shipment;
    }

    #[Asynchronous('partitionConfiguration.shipments')]
    #[EventHandler(endpointId: 'partitionConfiguration.shipment.whenChanged')]
    public function whenChanged(PartitionedOrderChanged $event): void
    {
        if ($event->change === 'lost') {
            throw new RuntimeException('shipment was lost');
        }

        $this->changes[] = $event->change;
    }
}

final class PartitionedWalletOpened
{
    public function __construct(public readonly string $walletId)
    {
    }
}

final class PartitionedWalletCredited
{
    public function __construct(public readonly string $walletId, public readonly int $amount)
    {
    }
}

#[EventSourcingAggregate]
final class PartitionedWallet
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $walletId;

    #[CommandHandler('partitionConfiguration.wallet.open')]
    public static function open(string $walletId): array
    {
        return [new PartitionedWalletOpened($walletId)];
    }

    #[CommandHandler('partitionConfiguration.wallet.credit')]
    public function credit(int $amount): array
    {
        return [new PartitionedWalletCredited($this->walletId, $amount)];
    }

    #[Apply]
    public function whenOpened(PartitionedWalletOpened $event): void
    {
        $this->walletId = $event->walletId;
    }
}
