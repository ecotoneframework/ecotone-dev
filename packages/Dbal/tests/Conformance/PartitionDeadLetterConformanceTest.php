<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\PartitionDeadLetter;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\Dbal\Gateway\HeldPartition;
use Ecotone\Api\Dbal\Gateway\PartitionDeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class PartitionDeadLetterConformanceTest extends DbalMessagingTestCase
{
    private object $orders;

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
        yield 'dbal with a dbal message channel' => ['dbal with a dbal message channel'];
    }

    #[DataProvider('implementations')]
    public function test_a_message_of_a_dead_lettered_partition_key_follows_it_without_being_handled(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $this->place($ecotone, 'broken', orderId: '1');
        $this->place($ecotone, 'coffee', orderId: '1');
        $this->consume($ecotone);

        self::assertNotContains('coffee', $this->orders->attempted);
        self::assertSame(2, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_a_message_of_another_partition_key_is_handled(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $this->place($ecotone, 'broken', orderId: '1');
        $this->place($ecotone, 'coffee', orderId: '2');
        $this->consume($ecotone);

        self::assertSame(['2:coffee'], $this->orders->placed);
        self::assertSame(1, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_a_message_without_a_partition_key_is_handled_while_another_key_is_dead_lettered(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $this->place($ecotone, 'broken', orderId: '1');
        $ecotone->sendCommandWithRouting('partitionDeadLetter.place', 'coffee');
        $this->consume($ecotone);

        self::assertSame([':coffee'], $this->orders->placed);
    }

    #[DataProvider('implementations')]
    public function test_a_message_that_followed_its_partition_key_names_the_message_it_followed(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        self::assertSame(
            'Partition key `1` of `partitionDeadLetter.place.endpoint` is held in the dead letter since message `m-1` failed, so this message follows it there without being handled. Replay the partition key to handle its messages in order with PartitionDeadLetterGateway::replay(\'partitionDeadLetter.place.endpoint\', \'1\') or the ecotone:deadletter:replayPartition console command, or delete its messages from the dead letter to let new ones through.',
            $ecotone->getGateway(DeadLetterGateway::class)->show('m-2')->getHeaders()->get(ErrorContext::EXCEPTION_MESSAGE),
        );
    }

    #[DataProvider('implementations')]
    public function test_replaying_the_oldest_message_of_a_partition_key_handles_the_rest_in_the_order_they_failed(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->place($ecotone, 'tea', orderId: '1', messageId: 'm-3');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee', '1:tea'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_a_message_is_not_replayed_before_the_older_ones_of_its_partition_key(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $this->expectExceptionMessage(
            'Dead letter message `m-2` can not be replayed before the older messages of its partition key `1` of `partitionDeadLetter.place.endpoint`, as that would handle them out of order. '
            . "Replay the oldest one, `m-1`, and the rest follow in order once it succeeds: PartitionDeadLetterGateway::replay('partitionDeadLetter.place.endpoint', '1') or the ecotone:deadletter:replayPartition console command do that. "
            . 'To handle this message first anyway, delete the older messages of the partition key from the dead letter.'
        );

        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2');
    }

    #[DataProvider('implementations')]
    public function test_a_message_is_not_replayed_while_an_older_one_of_its_partition_key_is_being_replayed(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');

        $this->expectExceptionMessage(
            'Dead letter message `m-2` can not be replayed while message `m-1` of its partition key `1` of `partitionDeadLetter.place.endpoint` is being replayed, as that would handle them out of order. '
            . 'Nothing has to be done: it is replayed once the messages before it succeed. '
            . "When the replayed message will never be handled (its channel was purged), delete the partition key with PartitionDeadLetterGateway::delete('partitionDeadLetter.place.endpoint', '1') or the ecotone:deadletter:deletePartition console command."
        );

        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2');
    }

    #[DataProvider('implementations')]
    public function test_a_partition_key_that_is_being_replayed_is_not_replayed_a_second_time(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1');
        $this->place($ecotone, 'coffee', orderId: '1');
        $this->consume($ecotone);
        $ecotone->getGateway(PartitionDeadLetterGateway::class)->replay('partitionDeadLetter.place.endpoint', '1');

        $this->expectExceptionMessage(
            'Partition key `1` of `partitionDeadLetter.place.endpoint` is being replayed already: one of its messages was released and is not handled yet, and the rest follow in order once it succeeds, so nothing has to be done. '
            . 'When that message will never be handled (its channel was purged), delete the partition key with PartitionDeadLetterGateway::delete() or the ecotone:deadletter:deletePartition console command.'
        );

        $ecotone->getGateway(PartitionDeadLetterGateway::class)->replay('partitionDeadLetter.place.endpoint', '1');
    }

    #[DataProvider('implementations')]
    public function test_a_released_message_delivered_again_after_it_was_handled_is_not_handled_a_second_time(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->consume($ecotone);
        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->consume($ecotone);

        $ecotone->sendCommandWithRouting('partitionDeadLetter.place', 'broken', metadata: ['orderId' => '1', 'id' => 'm-1', MessageHeaders::DEAD_LETTER_PARTITION_RELEASE => 'm-1']);
        $this->consume($ecotone);

        self::assertSame(['1:broken'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_a_released_message_delivered_again_lets_the_rest_of_its_partition_key_follow(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->consume($ecotone);
        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->consume($ecotone);
        $this->orders->broken = ['faulty'];
        $this->place($ecotone, 'faulty', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->sendCommandWithRouting('partitionDeadLetter.place', 'broken', metadata: ['orderId' => '1', 'id' => 'm-1', MessageHeaders::DEAD_LETTER_PARTITION_RELEASE => 'm-1']);
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:faulty'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_a_replayed_message_that_fails_again_keeps_its_partition_key_held_and_stays_the_oldest(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->consume($ecotone);

        self::assertSame([], $this->orders->placed);
        self::assertSame(2, $ecotone->getGateway(DeadLetterGateway::class)->count());

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee'], $this->orders->placed);
    }

    #[DataProvider('implementations')]
    public function test_a_message_arriving_while_its_partition_key_is_replayed_is_handled_after_the_ones_that_failed_before_it(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->place($ecotone, 'tea', orderId: '1', messageId: 'm-3');
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee', '1:tea'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_a_partition_key_whose_messages_were_all_replayed_is_handled_normally_again(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->consume($ecotone);
        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->consume($ecotone);

        $this->place($ecotone, 'coffee', orderId: '1');
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_replaying_all_dead_letters_handles_every_partition_key_in_its_own_order(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->orders->broken = ['broken', 'faulty'];
        $this->place($ecotone, 'broken', orderId: '1');
        $this->place($ecotone, 'faulty', orderId: '2');
        $this->place($ecotone, 'coffee', orderId: '1');
        $this->place($ecotone, 'tea', orderId: '2');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replayAll();
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee'], array_values(array_filter($this->orders->placed, fn (string $placed): bool => str_starts_with($placed, '1:'))));
        self::assertSame(['2:faulty', '2:tea'], array_values(array_filter($this->orders->placed, fn (string $placed): bool => str_starts_with($placed, '2:'))));
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_deleting_the_oldest_message_of_a_partition_key_keeps_the_key_held(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $ecotone->getGateway(DeadLetterGateway::class)->delete('m-1');
        $this->place($ecotone, 'tea', orderId: '1');
        $this->consume($ecotone);

        self::assertSame([], $this->orders->placed);
        self::assertSame(2, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_deleting_every_message_of_a_partition_key_lets_new_ones_through(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $ecotone->getGateway(DeadLetterGateway::class)->delete(['m-1', 'm-2']);
        $this->place($ecotone, 'tea', orderId: '1');
        $this->consume($ecotone);

        self::assertSame(['1:tea'], $this->orders->placed);
    }

    #[DataProvider('implementations')]
    public function test_held_partition_keys_are_listed_with_their_oldest_message(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->place($ecotone, 'broken', orderId: '2', messageId: 'm-3');
        $this->consume($ecotone);

        self::assertEquals(
            [
                new HeldPartition(trackingName: 'partitionDeadLetter.place.endpoint', partitionKey: '1', waitingMessages: 2, releasedMessages: 0, oldestMessageId: 'm-1'),
                new HeldPartition(trackingName: 'partitionDeadLetter.place.endpoint', partitionKey: '2', waitingMessages: 1, releasedMessages: 0, oldestMessageId: 'm-3'),
            ],
            $ecotone->getGateway(PartitionDeadLetterGateway::class)->list(),
        );
    }

    #[DataProvider('implementations')]
    public function test_replaying_a_partition_key_handles_its_messages_in_the_order_they_failed(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1');
        $this->place($ecotone, 'coffee', orderId: '1');
        $this->place($ecotone, 'broken', orderId: '2');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->getGateway(PartitionDeadLetterGateway::class)->replay('partitionDeadLetter.place.endpoint', '1');
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee'], $this->orders->placed);
        self::assertSame(1, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_replaying_a_partition_key_that_holds_nothing_is_refused(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $this->expectExceptionMessage(
            'The dead letter holds no message for partition key `7` of `partitionDeadLetter.place.endpoint`: its messages were replayed or deleted already, or the tracking name and the partition key do not name a held one. '
            . 'List the held partition keys with PartitionDeadLetterGateway::list() or the ecotone:deadletter:listPartitions console command.'
        );

        $ecotone->getGateway(PartitionDeadLetterGateway::class)->replay('partitionDeadLetter.place.endpoint', '7');
    }

    #[DataProvider('implementations')]
    public function test_deleting_a_partition_key_removes_its_messages_and_lets_new_ones_through(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1');
        $this->place($ecotone, 'coffee', orderId: '1');
        $this->consume($ecotone);

        $ecotone->getGateway(PartitionDeadLetterGateway::class)->delete('partitionDeadLetter.place.endpoint', '1');
        $this->place($ecotone, 'tea', orderId: '1');
        $this->consume($ecotone);

        self::assertSame(['1:tea'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
        self::assertSame([], $ecotone->getGateway(PartitionDeadLetterGateway::class)->list());
    }

    #[DataProvider('implementations')]
    public function test_a_partition_key_is_replayed_and_deleted_from_the_console(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->place($ecotone, 'broken', orderId: '2', messageId: 'm-3');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->runConsoleCommand('ecotone:deadletter:listPartitions');
        $ecotone->runConsoleCommand('ecotone:deadletter:replayPartition', ['trackingName' => 'partitionDeadLetter.place.endpoint', 'partitionKey' => '1']);
        $ecotone->runConsoleCommand('ecotone:deadletter:deletePartition', ['trackingName' => 'partitionDeadLetter.place.endpoint', 'partitionKey' => '2']);
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee'], $this->orders->placed);
        self::assertSame([], $ecotone->getGateway(PartitionDeadLetterGateway::class)->list());
    }

    private function place(FlowTestSupport $ecotone, string $item, string $orderId, ?string $messageId = null): void
    {
        $ecotone->sendCommandWithRouting(
            'partitionDeadLetter.place',
            $item,
            metadata: ['orderId' => $orderId] + ($messageId === null ? [] : ['id' => $messageId]),
        );
    }

    private function consume(FlowTestSupport $ecotone): void
    {
        $ecotone->run('partitionDeadLetter.orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
    }

    private function bootstrap(string $implementation): FlowTestSupport
    {
        $this->orders = new class () {
            public array $attempted = [];
            public array $placed = [];
            public array $broken = ['broken'];

            #[PartitionDeadLetter(partitionHeaderName: 'orderId')]
            #[Asynchronous('partitionDeadLetter.orders')]
            #[CommandHandler('partitionDeadLetter.place', endpointId: 'partitionDeadLetter.place.endpoint')]
            public function place(string $item, #[Header('orderId')] ?string $orderId = null): void
            {
                $this->attempted[] = $item;
                if (in_array($item, $this->broken, true)) {
                    throw new RuntimeException("cannot place {$item}");
                }

                $this->placed[] = $orderId . ':' . $item;
            }
        };

        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [$this->orders::class],
            containerOrAvailableServices: [$this->orders, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true, inMemory: $implementation === 'in-memory'),
                ...($implementation === 'dbal with a dbal message channel' ? [DbalBackedMessageChannelBuilder::create('partitionDeadLetter.orders')] : []),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $implementation === 'in-memory' ? $ecotone : $ecotone->setupDatabaseTables();
    }
}
