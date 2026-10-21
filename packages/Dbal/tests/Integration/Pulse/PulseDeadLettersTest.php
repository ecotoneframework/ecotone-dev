<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use DateTimeImmutable;
use DateTimeZone;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ErrorChannelExceptionMessages;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\LicenceTesting;
use Ecotone\Test\StaticMessageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

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
final class PulseDeadLettersTest extends DbalMessagingTestCase
{
    private const FAILED_AT = 1_792_000_000;

    private object $pulse;
    private object $handled;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;
    private array $channels;
    private bool $deadLetterIsConfigured = false;

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    public function tearDown(): void
    {
        $this->clearDeadLetters();
        parent::tearDown();
    }

    #[DataProvider('implementations')]
    public function test_the_snapshot_lists_dead_letters_grouped_by_message_and_exception(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom 1', self::FAILED_AT + 10);
        $this->deadLetter('m-2', 'order.place', 'RuntimeException', 'boom 2', self::FAILED_AT + 20);
        $this->deadLetter('m-3', 'order.place', 'LogicException', 'boom 3', self::FAILED_AT + 30);
        $this->deadLetter('m-4', 'order.cancel', 'RuntimeException', 'boom 4', self::FAILED_AT + 40);

        $deadLetters = $this->requestSnapshot()['deadLetters'];

        self::assertSame(4, $deadLetters['total']);
        self::assertEqualsCanonicalizing(
            [
                ['messageName' => 'order.place', 'exceptionClass' => 'RuntimeException', 'count' => 2],
                ['messageName' => 'order.place', 'exceptionClass' => 'LogicException', 'count' => 1],
                ['messageName' => 'order.cancel', 'exceptionClass' => 'RuntimeException', 'count' => 1],
            ],
            array_map(fn (array $group): array => array_intersect_key($group, array_flip(['messageName', 'exceptionClass', 'count'])), $deadLetters['groups']),
        );
    }

    #[DataProvider('implementations')]
    public function test_the_snapshot_of_a_single_tenant_service_has_no_tenancy_and_carries_the_dead_letters(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom 1', self::FAILED_AT);

        $snapshot = $this->requestSnapshot();

        self::assertArrayNotHasKey('tenancy', $snapshot);
        self::assertArrayNotHasKey('tenant', $snapshot);
        self::assertSame(1, $snapshot['deadLetters']['total']);
    }

    #[DataProvider('implementations')]
    public function test_recent_dead_letters_describe_each_failure_newest_first(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom 1', self::FAILED_AT, 'coffee');
        $this->deadLetter('m-2', 'order.cancel', 'LogicException', 'boom 2', self::FAILED_AT + 60, 'tea', [MessageHeaders::POLLED_CHANNEL_NAME => 'order.replayed']);

        $recent = $this->requestSnapshot()['deadLetters']['recent'];

        self::assertSame(
            [
                [
                    'messageId' => 'm-2',
                    'failureId' => $recent[0]['failureId'],
                    'messageName' => 'order.cancel',
                    'failedAt' => '2026-10-14T17:47:40+00:00',
                    'exceptionClass' => 'LogicException',
                    'exceptionFile' => 'handler.php',
                    'exceptionLine' => 1,
                    'exceptionMessage' => 'boom 2',
                    'stackTrace' => '#0 handler.php(1)',
                    'payload' => 'tea',
                    'contentType' => 'text/plain',
                    'channel' => 'order.replayed',
                    'traceId' => null,
                ],
                [
                    'messageId' => 'm-1',
                    'failureId' => $recent[1]['failureId'],
                    'messageName' => 'order.place',
                    'failedAt' => '2026-10-14T17:46:40+00:00',
                    'exceptionClass' => 'RuntimeException',
                    'exceptionFile' => 'handler.php',
                    'exceptionLine' => 1,
                    'exceptionMessage' => 'boom 1',
                    'stackTrace' => '#0 handler.php(1)',
                    'payload' => 'coffee',
                    'contentType' => 'text/plain',
                    'channel' => 'order.replayed',
                    'traceId' => null,
                ],
            ],
            $recent,
        );
    }

    #[DataProvider('implementations')]
    public function test_recent_dead_letters_are_capped_at_one_hundred_while_the_total_counts_all(string $implementation): void
    {
        $this->bootstrap($implementation);
        for ($position = 1; $position <= 105; $position++) {
            $this->deadLetter(sprintf('m-%03d', $position), 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + $position);
        }

        $deadLetters = $this->requestSnapshot()['deadLetters'];

        self::assertSame(105, $deadLetters['total']);
        self::assertCount(100, $deadLetters['recent']);
        self::assertSame('m-105', $deadLetters['recent'][0]['messageId']);
        self::assertSame('m-006', $deadLetters['recent'][99]['messageId']);
        self::assertCount(1, $deadLetters['groups']);
        self::assertSame(105, $deadLetters['groups'][0]['count']);
    }

    #[DataProvider('implementations')]
    public function test_a_long_payload_and_stack_trace_are_truncated_with_a_marker(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'a' . str_repeat('é', 3000), [ErrorContext::EXCEPTION_STACKTRACE => str_repeat('#', 5000)]);

        $recent = $this->requestSnapshot()['deadLetters']['recent'][0];

        self::assertLessThanOrEqual(4096, strlen($recent['payload']));
        self::assertStringEndsWith('... [truncated]', $recent['payload']);
        self::assertStringStartsWith('aéé', $recent['payload']);
        self::assertTrue(mb_check_encoding($recent['payload'], 'UTF-8'));
        self::assertSame(4096, strlen($recent['stackTrace']));
        self::assertStringEndsWith('... [truncated]', $recent['stackTrace']);
    }

    #[DataProvider('implementations')]
    public function test_a_payload_within_the_limit_is_reported_whole(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, str_repeat('a', 4096));

        self::assertSame(str_repeat('a', 4096), $this->requestSnapshot()['deadLetters']['recent'][0]['payload']);
    }

    #[DataProvider('implementations')]
    public function test_a_distributed_routing_key_names_the_message_before_its_type_id(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', '', 'RuntimeException', 'boom', self::FAILED_AT, headers: [MessageHeaders::TYPE_ID => 'App\\PlaceOrder']);
        $this->deadLetter('m-2', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + 1, headers: [MessageHeaders::TYPE_ID => 'App\\PlaceOrder']);

        $names = array_column($this->requestSnapshot()['deadLetters']['recent'], 'messageName', 'messageId');

        self::assertSame(['m-2' => 'order.place', 'm-1' => 'App\\PlaceOrder'], $names);
    }

    #[DataProvider('implementations')]
    public function test_a_message_carrying_no_name_is_reported_with_an_empty_message_name(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->orderService->getGateway(DeadLetterGateway::class)->store(
            StaticMessageBuilder::withPayload('x')->setMultipleHeaders([
                MessageHeaders::MESSAGE_ID => 'm-1',
                MessageHeaders::TIMESTAMP => self::FAILED_AT,
                ErrorContext::EXCEPTION_MESSAGE => 'boom',
                ErrorContext::EXCEPTION_STACKTRACE => '#0',
                ErrorContext::EXCEPTION_FILE => 'f.php',
                ErrorContext::EXCEPTION_LINE => 1,
                ErrorContext::EXCEPTION_CODE => 0,
            ])->build()
        );

        $entry = $this->requestSnapshot()['deadLetters']['recent'][0];

        self::assertSame('', $entry['messageName']);
        self::assertSame('', $entry['exceptionClass']);
        self::assertSame('f.php', $entry['exceptionFile']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $entry['failureId']);
        self::assertNull($entry['channel']);
        self::assertNull($entry['contentType']);
    }

    #[DataProvider('implementations')]
    public function test_an_empty_dead_letter_reports_an_empty_section(string $implementation): void
    {
        $this->bootstrap($implementation);

        self::assertSame(['total' => 0, 'groups' => [], 'recent' => []], $this->requestSnapshot()['deadLetters']);
    }

    #[DataProvider('implementations')]
    public function test_the_snapshot_says_the_dead_letter_is_enabled_even_when_it_is_empty(string $implementation): void
    {
        $this->bootstrap($implementation);

        self::assertSame(['enabled' => true], $this->requestSnapshot()['deadLetter']);
    }

    public function test_the_snapshot_says_the_dead_letter_is_not_enabled_when_it_is_disabled(): void
    {
        $this->bootstrap('in-memory', DbalConfiguration::createWithDefaults()->withDeadLetter(false));

        self::assertSame(['enabled' => false], $this->requestSnapshot()['deadLetter']);
    }

    public function test_the_snapshot_has_no_dead_letters_section_when_the_dead_letter_is_disabled(): void
    {
        $this->bootstrap('in-memory', DbalConfiguration::createWithDefaults()->withDeadLetter(false));

        self::assertArrayNotHasKey('deadLetters', $this->requestSnapshot());
    }

    #[DataProvider('implementations')]
    public function test_replaying_by_message_ids_runs_those_messages_again_and_replies_with_a_fresh_snapshot(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'coffee');
        $this->deadLetter('m-2', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + 1, 'tea');
        $this->deadLetter('m-3', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + 2, 'water');

        $snapshot = $this->replay(['messageIds' => ['m-1', 'm-3']]);

        self::assertSame(['coffee', 'water'], $this->sorted($this->handled->payloads));
        self::assertSame(1, $snapshot['deadLetters']['total']);
        self::assertSame(['m-2'], array_column($snapshot['deadLetters']['recent'], 'messageId'));
        self::assertSame('order_service', $snapshot['serviceName']);
    }

    #[DataProvider('implementations')]
    public function test_replaying_by_message_name_replays_every_matching_dead_letter_beyond_the_recent_ones(string $implementation): void
    {
        $this->bootstrap($implementation);
        for ($position = 1; $position <= 105; $position++) {
            $this->deadLetter(sprintf('m-%03d', $position), 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + $position, 'placed');
        }
        $this->deadLetter('m-cancel', 'order.cancel', 'RuntimeException', 'boom', self::FAILED_AT, 'cancelled');

        $snapshot = $this->replay(['filter' => ['messageName' => 'order.place']]);

        self::assertCount(105, $this->handled->payloads);
        self::assertSame(1, $snapshot['deadLetters']['total']);
        self::assertSame(['m-cancel'], array_column($snapshot['deadLetters']['recent'], 'messageId'));
    }

    #[DataProvider('implementations')]
    public function test_replaying_by_exception_class_replays_only_the_matching_failures(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'coffee');
        $this->deadLetter('m-2', 'order.place', 'LogicException', 'boom', self::FAILED_AT + 1, 'tea');

        $snapshot = $this->replay(['filter' => ['exceptionClass' => 'LogicException']]);

        self::assertSame(['tea'], $this->handled->payloads);
        self::assertSame(['m-1'], array_column($snapshot['deadLetters']['recent'], 'messageId'));
    }

    #[DataProvider('implementations')]
    public function test_replaying_by_time_range_replays_the_dead_letters_that_failed_within_it(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'early');
        $this->deadLetter('m-2', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + 3600, 'inside');
        $this->deadLetter('m-3', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + 7200, 'inside too');
        $this->deadLetter('m-4', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + 10800, 'late');

        $snapshot = $this->replay(['filter' => ['failedFrom' => '2026-10-14T18:46:40+00:00', 'failedTo' => '2026-10-14T19:46:40+00:00']]);

        self::assertSame(['inside', 'inside too'], $this->sorted($this->handled->payloads));
        self::assertSame(['m-4', 'm-1'], array_column($snapshot['deadLetters']['recent'], 'messageId'));
    }

    #[DataProvider('implementations')]
    public function test_a_time_range_includes_the_dead_letters_that_failed_exactly_at_both_bounds(string $implementation): void
    {
        $this->bootstrap($implementation);
        foreach (['before', 'from', 'to', 'after'] as $position => $payload) {
            $this->deadLetter('m-' . $payload, 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + $position, $payload);
        }

        $this->replay(['filter' => ['failedFrom' => gmdate(DATE_ATOM, self::FAILED_AT + 1), 'failedTo' => gmdate(DATE_ATOM, self::FAILED_AT + 2)]]);

        self::assertSame(['from', 'to'], $this->sorted($this->handled->payloads));
    }

    #[DataProvider('implementations')]
    public function test_a_time_range_that_ends_before_it_starts_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The `failedFrom` of a Pulse dead letter filter (2026-10-20T12:00:00+00:00) is later than its `failedTo` (2026-10-20T11:00:00+00:00), so no dead letter can match. Swap the two, or drop one of them.');
        $this->delete(['filter' => ['failedFrom' => '2026-10-20T12:00:00+00:00', 'failedTo' => '2026-10-20T11:00:00+00:00']]);
    }

    #[DataProvider('implementations')]
    public function test_a_filtered_replay_skips_a_dead_letter_another_replay_removed_in_the_meantime(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'one');
        $this->deadLetter('m-2', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + 1, 'two');
        $this->handled->deadLetter = $this->orderService->getGateway(DeadLetterGateway::class);
        $this->handled->deleteWhenReplayed = ['m-1'];

        $snapshot = $this->replay(['filter' => ['messageName' => 'order.place']]);

        self::assertSame(['two'], $this->handled->payloads);
        self::assertSame(0, $snapshot['deadLetters']['total']);
    }

    #[DataProvider('implementations')]
    public function test_a_filtered_delete_skips_a_dead_letter_removed_in_the_meantime(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'one');

        $this->orderService->getGateway(DeadLetterGateway::class)->delete('m-1');

        self::assertSame(0, $this->delete(['filter' => ['messageName' => 'order.place']])['deadLetters']['total']);
    }

    #[DataProvider('implementations')]
    public function test_a_filter_with_several_conditions_matches_all_of_them(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'coffee');
        $this->deadLetter('m-2', 'order.place', 'LogicException', 'boom', self::FAILED_AT, 'tea');
        $this->deadLetter('m-3', 'order.cancel', 'LogicException', 'boom', self::FAILED_AT, 'water');

        $this->replay(['filter' => ['messageName' => 'order.place', 'exceptionClass' => 'LogicException']]);

        self::assertSame(['tea'], $this->handled->payloads);
    }

    #[DataProvider('implementations')]
    public function test_a_group_describes_one_failure_with_its_newest_exception_message_and_its_first_and_last_seen(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'old boom', self::FAILED_AT, headers: $this->thrownAt('src/Place.php', 42));
        $this->deadLetter('m-2', 'order.place', 'RuntimeException', 'new boom', self::FAILED_AT + 60, headers: $this->thrownAt('src/Place.php', 42));

        $groups = $this->requestSnapshot()['deadLetters']['groups'];

        self::assertCount(1, $groups);
        self::assertSame(
            [
                'messageName' => 'order.place',
                'exceptionClass' => 'RuntimeException',
                'exceptionFile' => 'src/Place.php',
                'exceptionLine' => 42,
                'exceptionMessage' => 'new boom',
                'count' => 2,
                'firstFailedAt' => '2026-10-14T17:46:40+00:00',
                'lastFailedAt' => '2026-10-14T17:47:40+00:00',
            ],
            array_diff_key($groups[0], ['failureId' => true]),
        );
    }

    #[DataProvider('implementations')]
    public function test_groups_are_ordered_by_count_then_by_the_most_recent_failure(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'a', 'RuntimeException', 'boom', self::FAILED_AT + 10);
        $this->deadLetter('m-2', 'b', 'RuntimeException', 'boom', self::FAILED_AT + 90);
        $this->deadLetter('m-3', 'c', 'RuntimeException', 'boom', self::FAILED_AT + 50);
        $this->deadLetter('m-4', 'd', 'RuntimeException', 'boom', self::FAILED_AT + 20);
        $this->deadLetter('m-5', 'd', 'RuntimeException', 'boom', self::FAILED_AT + 21);

        self::assertSame(['d', 'b', 'c', 'a'], array_column($this->requestSnapshot()['deadLetters']['groups'], 'messageName'));
    }

    #[DataProvider('implementations')]
    public function test_the_failure_id_is_the_same_on_every_snapshot_of_the_same_failure(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, headers: $this->thrownAt('src/Place.php', 42));

        $first = $this->requestSnapshot()['deadLetters'];
        $this->deadLetter('m-2', 'order.place', 'RuntimeException', 'other text', self::FAILED_AT + 1, headers: $this->thrownAt('src/Place.php', 42));
        $second = $this->requestSnapshot()['deadLetters'];

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $first['groups'][0]['failureId']);
        self::assertSame($first['groups'][0]['failureId'], $second['groups'][0]['failureId']);
        self::assertSame($first['recent'][0]['failureId'], $first['groups'][0]['failureId']);
    }

    #[DataProvider('implementations')]
    public function test_the_same_exception_at_the_same_place_for_two_message_names_is_two_failures(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'placed', $this->thrownAt('src/Shared.php', 7));
        $this->deadLetter('m-2', 'order.cancel', 'RuntimeException', 'boom', self::FAILED_AT, 'cancelled', $this->thrownAt('src/Shared.php', 7));

        $groups = $this->requestSnapshot()['deadLetters']['groups'];

        self::assertCount(2, $groups);
        self::assertNotSame($groups[0]['failureId'], $groups[1]['failureId']);
    }

    #[DataProvider('implementations')]
    public function test_replaying_one_failure_leaves_the_same_exception_of_another_message_name_untouched(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'placed', $this->thrownAt('src/Shared.php', 7));
        $this->deadLetter('m-2', 'order.cancel', 'RuntimeException', 'boom', self::FAILED_AT, 'cancelled', $this->thrownAt('src/Shared.php', 7));

        $snapshot = $this->replay(['filter' => ['failureId' => $this->failureOf('order.place')]]);

        self::assertSame(['placed'], $this->handled->payloads);
        self::assertSame(['m-2'], array_column($snapshot['deadLetters']['recent'], 'messageId'));
    }

    #[DataProvider('implementations')]
    public function test_the_same_message_and_exception_at_two_lines_is_two_failures(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, headers: $this->thrownAt('src/Place.php', 10));
        $this->deadLetter('m-2', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, headers: $this->thrownAt('src/Place.php', 20));

        self::assertCount(2, $this->requestSnapshot()['deadLetters']['groups']);
    }

    #[DataProvider('implementations')]
    public function test_different_exception_messages_from_the_same_place_are_one_failure(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'order 1 missing', self::FAILED_AT, headers: $this->thrownAt('src/Place.php', 10));
        $this->deadLetter('m-2', 'order.place', 'RuntimeException', 'order 2 missing', self::FAILED_AT, headers: $this->thrownAt('src/Place.php', 10));

        $groups = $this->requestSnapshot()['deadLetters']['groups'];

        self::assertCount(1, $groups);
        self::assertSame(2, $groups[0]['count']);
    }

    #[DataProvider('implementations')]
    public function test_a_dead_letter_with_an_empty_throw_location_is_still_grouped_by_message_and_exception(string $implementation): void
    {
        $this->bootstrap($implementation);
        foreach (['m-1', 'm-2'] as $messageId) {
            $this->orderService->getGateway(DeadLetterGateway::class)->store(
                StaticMessageBuilder::withPayload('x')->setMultipleHeaders([
                    MessageHeaders::MESSAGE_ID => $messageId,
                    MessageHeaders::TIMESTAMP => self::FAILED_AT,
                    DistributedBusHeader::DISTRIBUTED_ROUTING_KEY => 'order.place',
                    ErrorContext::EXCEPTION_CLASS => 'RuntimeException',
                    ErrorContext::EXCEPTION_MESSAGE => 'boom',
                    ErrorContext::EXCEPTION_STACKTRACE => '#0',
                    ErrorContext::EXCEPTION_FILE => '',
                    ErrorContext::EXCEPTION_LINE => 0,
                    ErrorContext::EXCEPTION_CODE => 0,
                ])->build()
            );
        }

        $groups = $this->requestSnapshot()['deadLetters']['groups'];

        self::assertCount(1, $groups);
        self::assertSame(2, $groups[0]['count']);
        self::assertSame('', $groups[0]['exceptionFile']);
        self::assertSame(0, $groups[0]['exceptionLine']);
    }

    #[DataProvider('implementations')]
    public function test_replaying_by_failure_replays_every_matching_dead_letter_beyond_the_recent_ones(string $implementation): void
    {
        $this->bootstrap($implementation);
        for ($position = 1; $position <= 105; $position++) {
            $this->deadLetter(sprintf('m-%03d', $position), 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + $position, 'placed', $this->thrownAt('src/Place.php', 10));
        }
        $this->deadLetter('m-other', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'other', $this->thrownAt('src/Place.php', 99));

        $snapshot = $this->replay(['filter' => ['failureId' => $this->failureOf('order.place', 10)]]);

        self::assertCount(105, $this->handled->payloads);
        self::assertSame(1, $snapshot['deadLetters']['total']);
        self::assertSame(105, $snapshot['deadLetterAction']['affected']);
    }

    #[DataProvider('implementations')]
    public function test_replaying_an_unknown_failure_affects_nothing(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'placed');

        $snapshot = $this->replay(['filter' => ['failureId' => 'ffffffffffff']]);

        self::assertSame([], $this->handled->payloads);
        self::assertSame(1, $snapshot['deadLetters']['total']);
        self::assertSame(0, $snapshot['deadLetterAction']['affected']);
    }

    #[DataProvider('implementations')]
    public function test_a_failure_combines_with_a_time_range(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'early');
        $this->deadLetter('m-2', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + 3600, 'late');

        $this->replay(['filter' => ['failureId' => $this->failureOf('order.place'), 'failedFrom' => '2026-10-14T18:00:00+00:00']]);

        self::assertSame(['late'], $this->handled->payloads);
    }

    #[DataProvider('implementations')]
    public function test_deleting_by_failure_removes_only_that_failure_without_running_it(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, headers: $this->thrownAt('src/Shared.php', 7));
        $this->deadLetter('m-2', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, headers: $this->thrownAt('src/Shared.php', 7));
        $this->deadLetter('m-3', 'order.cancel', 'RuntimeException', 'boom', self::FAILED_AT, headers: $this->thrownAt('src/Shared.php', 7));

        $snapshot = $this->delete(['filter' => ['failureId' => $this->failureOf('order.place')]]);

        self::assertSame([], $this->handled->payloads);
        self::assertSame(['m-3'], array_column($snapshot['deadLetters']['recent'], 'messageId'));
        self::assertSame(2, $snapshot['deadLetterAction']['affected']);
    }

    #[DataProvider('implementations')]
    public function test_replaying_by_failure_to_an_asynchronous_target_channel_runs_the_handler_once_that_channel_is_consumed(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'coffee', [MessageHeaders::ROUTING_SLIP => 'order.audited.execute']);

        $this->replay(['filter' => ['failureId' => $this->failureOf('order.place')], 'targetChannel' => 'order_async']);
        self::assertSame([], $this->handled->audited);
        $this->orderService->run('order_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['coffee'], $this->handled->audited);
    }

    #[DataProvider('implementations')]
    public function test_replaying_by_failure_to_an_unknown_target_channel_is_refused_and_keeps_the_dead_letters(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'coffee');

        try {
            $this->replay(['filter' => ['failureId' => $this->failureOf('order.place')], 'targetChannel' => 'no_such_channel']);
            self::fail('An unknown target channel was not refused');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(ErrorChannelExceptionMessages::cannotReplayToUnknownChannel('no_such_channel'), $exception->getMessage());
        }

        self::assertSame(1, $this->orderService->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_replaying_to_an_asynchronous_target_channel_runs_the_original_handler_once_that_channel_is_consumed(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'coffee', [MessageHeaders::ROUTING_SLIP => 'order.audited.execute']);

        $snapshot = $this->replay(['messageIds' => ['m-1'], 'targetChannel' => 'order_async']);
        self::assertSame([], $this->handled->audited);
        $this->orderService->run('order_async', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([], $this->handled->payloads);
        self::assertSame(['coffee'], $this->handled->audited);
        self::assertSame(0, $snapshot['deadLetters']['total']);
    }

    #[DataProvider('implementations')]
    public function test_replaying_to_a_target_channel_that_is_not_asynchronous_is_refused_and_keeps_the_dead_letters(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'coffee');

        try {
            $this->replay(['messageIds' => ['m-1'], 'targetChannel' => 'order.replayed']);
            self::fail('The replay to a channel that is not asynchronous was not refused');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(ErrorChannelExceptionMessages::cannotReplayToNonAsynchronousChannel('order.replayed'), $exception->getMessage());
        }

        self::assertSame(1, $this->orderService->getGateway(DeadLetterGateway::class)->count());
        self::assertSame([], $this->handled->payloads);
    }

    #[DataProvider('implementations')]
    public function test_replaying_to_an_unknown_target_channel_is_refused_and_keeps_the_dead_letters(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'coffee');

        try {
            $this->replay(['messageIds' => ['m-1'], 'targetChannel' => 'order.missing']);
            self::fail('The replay to an unknown channel was not refused');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(ErrorChannelExceptionMessages::cannotReplayToUnknownChannel('order.missing'), $exception->getMessage());
        }

        self::assertSame(1, $this->orderService->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_replaying_a_filter_that_matches_nothing_replies_with_the_snapshot_untouched(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'coffee');

        $snapshot = $this->replay(['filter' => ['messageName' => 'order.unknown']]);

        self::assertSame([], $this->handled->payloads);
        self::assertSame(1, $snapshot['deadLetters']['total']);
    }

    #[DataProvider('implementations')]
    public function test_deleting_by_message_ids_removes_them_without_running_them(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'coffee');
        $this->deadLetter('m-2', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + 1, 'tea');

        $snapshot = $this->delete(['messageIds' => ['m-1']]);

        self::assertSame([], $this->handled->payloads);
        self::assertSame(['m-2'], array_column($snapshot['deadLetters']['recent'], 'messageId'));
        self::assertSame(1, $snapshot['deadLetters']['total']);
    }

    #[DataProvider('implementations')]
    public function test_deleting_by_filter_removes_every_matching_dead_letter_beyond_the_recent_ones(string $implementation): void
    {
        $this->bootstrap($implementation);
        for ($position = 1; $position <= 105; $position++) {
            $this->deadLetter(sprintf('m-%03d', $position), 'order.place', 'RuntimeException', 'boom', self::FAILED_AT + $position);
        }
        $this->deadLetter('m-cancel', 'order.cancel', 'LogicException', 'boom', self::FAILED_AT);

        $snapshot = $this->delete(['filter' => ['exceptionClass' => 'RuntimeException']]);

        self::assertSame([], $this->handled->payloads);
        self::assertSame(1, $snapshot['deadLetters']['total']);
        self::assertSame(['m-cancel'], array_column($snapshot['deadLetters']['recent'], 'messageId'));
    }

    #[DataProvider('implementations')]
    public function test_a_selection_by_both_message_ids_and_filter_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A Pulse dead letter action selects messages with exactly one of `messageIds` or `filter`, but the payload had both. Send {"messageIds": ["id-1"]} to act on those messages, or {"filter": {"messageName": "order.place"}} to act on every dead letter matching it.');
        $this->replay(['messageIds' => ['m-1'], 'filter' => ['messageName' => 'order.place']]);
    }

    #[DataProvider('implementations')]
    public function test_a_selection_by_neither_message_ids_nor_filter_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A Pulse dead letter action selects messages with exactly one of `messageIds` or `filter`, but the payload had neither. Send {"messageIds": ["id-1"]} to act on those messages, or {"filter": {"messageName": "order.place"}} to act on every dead letter matching it.');
        $this->ask('ecotone.pulse.dead_letter.delete', '{}');
    }

    #[DataProvider('implementations')]
    public function test_an_empty_filter_is_refused_instead_of_matching_every_dead_letter(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT);

        try {
            $this->delete(['filter' => new stdClass()]);
            self::fail('An empty filter was not refused');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The `filter` of a Pulse dead letter action is empty, and an empty filter would match every dead letter. Name at least one of messageName, exceptionClass, failureId, failedFrom, failedTo, or select the messages by id with `messageIds`.', $exception->getMessage());
        }

        self::assertSame(1, $this->orderService->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('implementations')]
    public function test_a_filter_with_an_unknown_condition_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The `filter` of a Pulse dead letter action has an unknown condition `all`. Use only messageName, exceptionClass, failureId, failedFrom, failedTo.');
        $this->delete(['filter' => ['all' => true]]);
    }

    #[DataProvider('implementations')]
    public function test_a_filter_with_a_malformed_time_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The `failedFrom` of a Pulse dead letter filter is `yesterday`, which is not an ISO-8601 date time. Send it as in 2026-10-20T12:00:00+00:00.');
        $this->delete(['filter' => ['failedFrom' => 'yesterday']]);
    }

    #[DataProvider('implementations')]
    public function test_message_ids_that_are_not_a_list_of_names_are_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The `messageIds` of a Pulse dead letter action must be a non-empty list of message id strings, as in {"messageIds": ["id-1", "id-2"]}.');
        $this->delete(['messageIds' => []]);
    }

    #[DataProvider('implementations')]
    public function test_deleting_with_a_target_channel_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A Pulse dead letter delete takes no `targetChannel`, as a deleted message is not sent anywhere. Remove `targetChannel` from the payload, or use the replay action to send the messages to a channel.');
        $this->delete(['messageIds' => ['m-1'], 'targetChannel' => 'order.audited']);
    }

    #[DataProvider('implementations')]
    public function test_a_payload_that_is_not_a_json_object_is_refused(string $implementation): void
    {
        $this->bootstrap($implementation);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A Pulse dead letter action payload must be a JSON object such as {"messageIds": ["id-1"]} or {"filter": {"messageName": "order.place"}}, but `nope` was sent.');
        $this->ask('ecotone.pulse.dead_letter.delete', 'nope');
    }

    #[DataProvider('implementations')]
    public function test_an_action_from_a_service_other_than_the_pulse_service_is_refused_and_acts_on_nothing(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, 'coffee');
        $intruder = $this->bootstrapFlowTesting(
            extensionObjects: [
                ...$this->channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('intruder'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $intruder->getDistributedBus()->sendMessage('order_service', 'ecotone.pulse.dead_letter.delete', '{"messageIds": ["m-1"]}', MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');

        try {
            $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
            self::fail('The action from another service was not refused');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('only answers the configured Pulse service ecotone_pulse', $exception->getMessage());
        }

        self::assertSame(1, $this->orderService->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_an_action_without_a_dead_letter_is_refused_with_how_to_enable_it(): void
    {
        $this->bootstrap('in-memory', DbalConfiguration::createWithDefaults()->withDeadLetter(false));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service order_service was asked by Pulse to act on its dead letters, but no dead letter is configured there, so it holds none. Enable it with DbalConfiguration::createWithDefaults()->withDeadLetter(true) in a #[ServiceContext] of order_service, or stop sending dead letter actions to this service.');
        $this->delete(['messageIds' => ['m-1']]);
    }

    private function bootstrap(string $implementation, ?DbalConfiguration $dbalConfiguration = null): void
    {
        $this->handled = new class () {
            public array $payloads = [];
            public array $audited = [];
            public ?DeadLetterGateway $deadLetter = null;
            public array $deleteWhenReplayed = [];

            #[InternalHandler('order.replayed')]
            public function replayed(string $payload): void
            {
                $this->payloads[] = $payload;
                foreach ($this->deleteWhenReplayed as $messageId) {
                    $this->deadLetter->delete($messageId);
                }
            }

            #[Asynchronous('order_async')]
            #[InternalHandler('order.audited', endpointId: 'order.audited.endpoint')]
            public function audited(string $payload): void
            {
                $this->audited[] = $payload;
            }
        };
        $this->pulse = new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }
        };
        $this->channels = $channels = [SimpleMessageChannelBuilder::createQueueChannel('order_channel'), SimpleMessageChannelBuilder::createQueueChannel('pulse_channel')];
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->handled::class],
            containerOrAvailableServices: [$this->handled, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                SimpleMessageChannelBuilder::createQueueChannel('order_async'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                $dbalConfiguration ?? DbalConfiguration::createWithDefaults()->withDeadLetter(true, inMemory: $implementation === 'in-memory'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC'))),
        );
        $this->orderService->setupDatabaseTables();
        $this->deadLetterIsConfigured = $dbalConfiguration === null;
        $this->clearDeadLetters();
        $this->pulseService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->pulse::class],
            containerOrAvailableServices: [$this->pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function deadLetter(string $messageId, string $messageName, string $exceptionClass, string $exceptionMessage, int $failedAt, string $payload = 'payload', array $headers = []): void
    {
        $this->orderService->getGateway(DeadLetterGateway::class)->store(
            StaticMessageBuilder::withPayload($payload)
                ->setMultipleHeaders([
                    MessageHeaders::MESSAGE_ID => $messageId,
                    MessageHeaders::TIMESTAMP => $failedAt,
                    ...($messageName === '' ? [] : [DistributedBusHeader::DISTRIBUTED_ROUTING_KEY => $messageName]),
                    MessageHeaders::CONTENT_TYPE => MediaType::TEXT_PLAIN,
                    MessageHeaders::POLLED_CHANNEL_NAME => 'order.replayed',
                    ErrorContext::EXCEPTION_CLASS => $exceptionClass,
                    ErrorContext::EXCEPTION_MESSAGE => $exceptionMessage,
                    ErrorContext::EXCEPTION_STACKTRACE => '#0 handler.php(1)',
                    ErrorContext::EXCEPTION_FILE => 'handler.php',
                    ErrorContext::EXCEPTION_LINE => 1,
                    ErrorContext::EXCEPTION_CODE => 0,
                    ...$headers,
                ])
                ->build()
        );
    }

    private function thrownAt(string $file, int $line): array
    {
        return [ErrorContext::EXCEPTION_FILE => $file, ErrorContext::EXCEPTION_LINE => $line];
    }

    private function failureOf(string $messageName, ?int $line = null): string
    {
        foreach ($this->requestSnapshot()['deadLetters']['groups'] as $group) {
            if ($group['messageName'] === $messageName && ($line === null || $group['exceptionLine'] === $line)) {
                return $group['failureId'];
            }
        }

        self::fail("No failure for {$messageName}");
    }

    private function replay(array $payload): array
    {
        return $this->ask('ecotone.pulse.dead_letter.replay', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function delete(array $payload): array
    {
        return $this->ask('ecotone.pulse.dead_letter.delete', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function requestSnapshot(): array
    {
        return $this->ask('ecotone.pulse.snapshot', '{}');
    }

    private function ask(string $routingKey, string $payload): array
    {
        $received = count($this->pulse->snapshots);
        $this->pulseService->getDistributedBus()->sendMessage('order_service', $routingKey, $payload, MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertCount($received + 1, $this->pulse->snapshots);

        return json_decode($this->pulse->snapshots[$received], true, flags: JSON_THROW_ON_ERROR);
    }

    private function clearDeadLetters(): void
    {
        if ($this->deadLetterIsConfigured) {
            $this->orderService->getGateway(DeadLetterGateway::class)->deleteAll();
        }
    }
}
