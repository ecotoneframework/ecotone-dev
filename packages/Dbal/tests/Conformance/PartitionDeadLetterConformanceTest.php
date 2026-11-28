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
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
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
    private PartitionedOrders $orders;

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
            'Partition key `1` of `' . PartitionedOrders::class . '` is held in the dead letter since message `m-1` failed, so this message follows it there without being handled. '
            . "Replay any message of the partition key to handle them all in the order they failed, with DeadLetterGateway::replay('m-1') or the ecotone:deadletter:replay console command; replay only this message, out of that order, with ignorePartitionOrder: true or --ignorePartitionOrder; or delete the messages of the partition key from the dead letter to let new ones through.",
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
    public function test_the_dead_letter_listing_names_the_partition_key_and_the_position_of_each_held_message(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->place($ecotone, 'broken', orderId: '2', messageId: 'm-3');
        $ecotone->sendCommandWithRouting('partitionDeadLetter.place', 'broken', metadata: ['id' => 'm-4']);
        $this->consume($ecotone);

        $listed = [];
        foreach ($ecotone->getGateway(DeadLetterGateway::class)->list(100, 0) as $errorContext) {
            $listed[$errorContext->getMessageId()] = [$errorContext->partitionTrackingName, $errorContext->partitionKey, $errorContext->partitionPosition];
        }
        ksort($listed);

        self::assertSame(
            [
                'm-1' => [PartitionedOrders::class, '1', 1],
                'm-2' => [PartitionedOrders::class, '1', 2],
                'm-3' => [PartitionedOrders::class, '2', 1],
                'm-4' => [null, null, null],
            ],
            $listed,
        );
    }

    #[DataProvider('implementations')]
    public function test_a_shown_dead_letter_names_its_partition_key_and_position_in_its_headers(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $headers = $ecotone->getGateway(DeadLetterGateway::class)->show('m-2')->getHeaders();

        self::assertSame(
            [PartitionedOrders::class, '1', 2],
            [$headers->get(MessageHeaders::DEAD_LETTER_PARTITION_TRACKING_NAME), $headers->get(MessageHeaders::DEAD_LETTER_PARTITION_KEY), $headers->get(MessageHeaders::DEAD_LETTER_PARTITION_POSITION)],
        );
    }

    #[DataProvider('implementations')]
    public function test_replaying_a_later_message_of_a_held_partition_key_handles_its_key_from_the_oldest(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->place($ecotone, 'tea', orderId: '1', messageId: 'm-3');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2');
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee', '1:tea'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_replaying_a_later_message_ignoring_the_partition_order_handles_only_that_message(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2', ignorePartitionOrder: true);
        $this->consume($ecotone);

        self::assertSame(['1:coffee'], $this->orders->placed);
        self::assertSame(['1' => ['m-1']], $this->heldMessageIdsByPartitionKey($ecotone));
    }

    #[DataProvider('implementations')]
    public function test_replaying_several_messages_of_a_held_partition_key_starts_the_key_once_from_its_oldest(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->place($ecotone, 'tea', orderId: '1', messageId: 'm-3');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay(['m-3', 'm-1', 'm-2']);
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee', '1:tea'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_a_partition_key_stays_held_after_one_of_its_messages_was_replayed_ignoring_the_partition_order(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2', ignorePartitionOrder: true);
        $this->consume($ecotone);

        $this->place($ecotone, 'tea', orderId: '1', messageId: 'm-3');
        $this->consume($ecotone);

        self::assertSame(['1:coffee'], $this->orders->placed);
        self::assertSame(['1' => ['m-1', 'm-3']], $this->heldMessageIdsByPartitionKey($ecotone));
    }

    #[DataProvider('implementations')]
    public function test_replaying_the_oldest_message_ignoring_the_partition_order_leaves_the_rest_of_its_key_held(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1', ignorePartitionOrder: true);
        $this->consume($ecotone);

        self::assertSame(['1:broken'], $this->orders->placed);
        self::assertSame(['1' => ['m-2']], $this->heldMessageIdsByPartitionKey($ecotone));
    }

    #[DataProvider('implementations')]
    public function test_a_message_replayed_ignoring_the_partition_order_that_fails_again_returns_to_its_position(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->orders->broken = ['broken', 'coffee'];
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->place($ecotone, 'tea', orderId: '1', messageId: 'm-3');
        $this->consume($ecotone);

        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2', ignorePartitionOrder: true);
        $this->consume($ecotone);

        self::assertSame([], $this->orders->placed);
        self::assertSame(['1' => ['m-1', 'm-2', 'm-3']], $this->heldMessageIdsByPartitionKey($ecotone));
    }

    #[DataProvider('implementations')]
    public function test_a_message_that_holds_no_partition_key_is_replayed_ignoring_the_partition_order_as_any_other(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $ecotone->sendCommandWithRouting('partitionDeadLetter.place', 'broken', metadata: ['id' => 'm-1']);
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1', ignorePartitionOrder: true);
        $this->consume($ecotone);

        self::assertSame([':broken'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_the_console_lists_the_partition_key_and_position_of_held_messages(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $ecotone->sendCommandWithRouting('partitionDeadLetter.place', 'broken', metadata: ['id' => 'm-3']);
        $this->consume($ecotone);

        $listed = $ecotone->getGateway(ConsoleCommandRunner::class)->execute('ecotone:deadletter:list', []);
        $partitions = [];
        foreach ($listed->rows as $row) {
            $partitions[$row[0]] = array_slice($row, 3);
        }
        ksort($partitions);

        self::assertSame(['Message Id', 'Failed At', 'Stacktrace', 'Partition Key', 'Partition Position', 'Partition Tracking Name'], $listed->columnHeaders);
        self::assertSame(
            ['m-1' => ['1', 1, PartitionedOrders::class], 'm-2' => ['1', 2, PartitionedOrders::class], 'm-3' => ['', '', '']],
            $partitions,
        );
    }

    #[DataProvider('implementations')]
    public function test_the_console_lists_dead_letters_without_partition_columns_when_none_of_them_is_held(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $ecotone->sendCommandWithRouting('partitionDeadLetter.place', 'broken', metadata: ['id' => 'm-1']);
        $this->consume($ecotone);

        self::assertSame(
            ['Message Id', 'Failed At', 'Stacktrace'],
            $ecotone->getGateway(ConsoleCommandRunner::class)->execute('ecotone:deadletter:list', [])->columnHeaders,
        );
    }

    #[DataProvider('implementations')]
    public function test_the_console_shows_the_partition_key_position_and_tracking_name_of_a_held_message(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $shown = array_column($ecotone->getGateway(ConsoleCommandRunner::class)->execute('ecotone:deadletter:show', ['messageId' => 'm-2'])->rows, 1, 0);

        self::assertSame(
            ['1', 2, PartitionedOrders::class],
            [$shown['Partition Key'], $shown['Partition Position'], $shown['Partition Tracking Name']],
        );
    }

    #[DataProvider('implementations')]
    public function test_the_console_shows_a_message_that_is_not_held_without_partition_rows(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $ecotone->sendCommandWithRouting('partitionDeadLetter.place', 'broken', metadata: ['id' => 'm-1']);
        $this->consume($ecotone);

        self::assertSame(
            ['Message Id', 'Failed At', 'Channel Name', 'Type', 'Stacktrace'],
            array_column($ecotone->getGateway(ConsoleCommandRunner::class)->execute('ecotone:deadletter:show', ['messageId' => 'm-1'])->rows, 0),
        );
    }

    #[DataProvider('implementations')]
    public function test_the_console_replays_a_later_message_of_a_held_partition_key_from_the_oldest(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->runConsoleCommand('ecotone:deadletter:replay', ['messageId' => 'm-2']);
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee'], $this->orders->placed);
    }

    #[DataProvider('implementations')]
    public function test_the_console_replays_only_the_given_message_with_the_ignore_partition_order_option(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->runConsoleCommand('ecotone:deadletter:replay', ['messageId' => 'm-2', 'ignorePartitionOrder' => true]);
        $this->consume($ecotone);

        self::assertSame(['1:coffee'], $this->orders->placed);
        self::assertSame(['1' => ['m-1']], $this->heldMessageIdsByPartitionKey($ecotone));
    }

    #[DataProvider('implementations')]
    public function test_replaying_all_twice_while_the_oldest_message_of_a_partition_key_is_on_its_way_back_keeps_the_key_in_order_when_that_message_fails_again(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $ecotone->getGateway(DeadLetterGateway::class)->replayAll();
        $ecotone->getGateway(DeadLetterGateway::class)->replayAll();
        $this->consume($ecotone);

        self::assertSame([], $this->orders->placed);
        self::assertSame(['1' => ['m-1', 'm-2']], $this->heldMessageIdsByPartitionKey($ecotone));

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replayAll();
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_replaying_two_messages_of_a_partition_key_one_after_another_keeps_the_key_in_order_when_the_older_one_fails_again(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2');
        $this->consume($ecotone);

        self::assertSame([], $this->orders->placed);
        self::assertSame(['1' => ['m-1', 'm-2']], $this->heldMessageIdsByPartitionKey($ecotone));

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2');
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_a_message_is_replayed_while_an_older_one_of_its_partition_key_is_on_its_way_back(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2');
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee'], $this->orders->placed);
        self::assertSame([], $this->heldMessageIdsByPartitionKey($ecotone));
    }

    #[DataProvider('implementations')]
    public function test_a_partition_key_stays_held_by_the_messages_still_in_the_dead_letter_while_its_oldest_one_is_on_its_way_back(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->consume($ecotone);

        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');

        self::assertSame(['1' => ['m-2']], $this->heldMessageIdsByPartitionKey($ecotone));
    }

    #[DataProvider('implementations')]
    public function test_a_replayed_message_delivered_again_after_it_was_handled_is_not_handled_a_second_time(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->consume($ecotone);
        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->consume($ecotone);

        $ecotone->sendCommandWithRouting('partitionDeadLetter.place', 'broken', metadata: ['orderId' => '1', 'id' => 'm-1', MessageHeaders::DEAD_LETTER_PARTITION_RELEASE => '1']);
        $this->consume($ecotone);

        self::assertSame(['1:broken'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_a_replayed_message_delivered_again_lets_the_rest_of_its_partition_key_follow(string $implementation): void
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
        $ecotone->sendCommandWithRouting('partitionDeadLetter.place', 'broken', metadata: ['orderId' => '1', 'id' => 'm-1', MessageHeaders::DEAD_LETTER_PARTITION_RELEASE => '1']);
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
    public function test_a_message_arriving_while_its_partition_key_still_has_messages_in_the_dead_letter_is_handled_after_them(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->place($ecotone, 'coffee', orderId: '1', messageId: 'm-2');
        $this->place($ecotone, 'cocoa', orderId: '1', messageId: 'm-3');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->place($ecotone, 'tea', orderId: '1', messageId: 'm-4');
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:coffee', '1:cocoa', '1:tea'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_a_partition_key_is_not_held_while_its_last_message_is_on_its_way_back_and_is_held_again_when_that_message_fails(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-1');
        $this->consume($ecotone);

        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        self::assertSame([], $this->heldMessageIdsByPartitionKey($ecotone));

        $this->consume($ecotone);
        self::assertSame(['1' => ['m-1']], $this->heldMessageIdsByPartitionKey($ecotone));
    }

    #[DataProvider('implementations')]
    public function test_a_replayed_message_that_fails_again_is_replayed_before_a_message_of_its_partition_key_that_failed_while_it_was_on_its_way_back(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $this->orders->broken = ['broken', 'faulty'];
        $this->place($ecotone, 'broken', orderId: '1', messageId: 'm-2', sentAt: 1700000000);
        $this->consume($ecotone);

        $this->place($ecotone, 'faulty', orderId: '1', messageId: 'm-1', sentAt: 1700000000);
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-2');
        $this->consume($ecotone);

        $this->orders->broken = [];
        $ecotone->getGateway(DeadLetterGateway::class)->replayAll();
        $this->consume($ecotone);

        self::assertSame(['1:broken', '1:faulty'], $this->orders->placed);
        self::assertSame([], $this->heldMessageIdsByPartitionKey($ecotone));
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

    /**
     * @return array<string, list<string>>
     */
    private function heldMessageIdsByPartitionKey(FlowTestSupport $ecotone): array
    {
        $held = [];
        foreach ($ecotone->getGateway(DeadLetterGateway::class)->list(100, 0) as $errorContext) {
            if ($errorContext->partitionKey !== null) {
                $held[$errorContext->partitionKey][$errorContext->partitionPosition] = $errorContext->getMessageId();
            }
        }
        ksort($held);

        return array_map(static function (array $messageIdsByPosition): array {
            ksort($messageIdsByPosition);

            return array_values($messageIdsByPosition);
        }, $held);
    }

    private function place(FlowTestSupport $ecotone, string $item, string $orderId, ?string $messageId = null, ?int $sentAt = null): void
    {
        $ecotone->sendCommandWithRouting(
            'partitionDeadLetter.place',
            $item,
            metadata: ['orderId' => $orderId] + ($messageId === null ? [] : ['id' => $messageId]) + ($sentAt === null ? [] : ['timestamp' => $sentAt]),
        );
    }

    private function consume(FlowTestSupport $ecotone): void
    {
        $ecotone->run('partitionDeadLetter.orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
    }

    private function bootstrap(string $implementation): FlowTestSupport
    {
        $this->orders = new PartitionedOrders();

        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [PartitionedOrders::class],
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

final class PartitionedOrders
{
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
}
