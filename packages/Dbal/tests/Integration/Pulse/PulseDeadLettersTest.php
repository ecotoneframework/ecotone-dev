<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use DateTimeImmutable;
use DateTimeZone;
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
use Ecotone\Dbal\Recoverability\DbalDeadLetterStore;
use Ecotone\Test\LicenceTesting;
use Ecotone\Test\StaticMessageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->dropDeadLetterTable();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->dropDeadLetterTable();
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
            $deadLetters['groups'],
        );
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
                    'messageName' => 'order.cancel',
                    'failedAt' => '2026-10-14T17:47:40+00:00',
                    'exceptionClass' => 'LogicException',
                    'exceptionMessage' => 'boom 2',
                    'stackTrace' => '#0 handler.php(1)',
                    'payload' => 'tea',
                    'contentType' => 'text/plain',
                    'channel' => 'order.replayed',
                ],
                [
                    'messageId' => 'm-1',
                    'messageName' => 'order.place',
                    'failedAt' => '2026-10-14T17:46:40+00:00',
                    'exceptionClass' => 'RuntimeException',
                    'exceptionMessage' => 'boom 1',
                    'stackTrace' => '#0 handler.php(1)',
                    'payload' => 'coffee',
                    'contentType' => 'text/plain',
                    'channel' => 'order.replayed',
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
        self::assertSame([['messageName' => 'order.place', 'exceptionClass' => 'RuntimeException', 'count' => 105]], $deadLetters['groups']);
    }

    #[DataProvider('implementations')]
    public function test_a_long_payload_and_stack_trace_are_truncated_with_a_marker(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->deadLetter('m-1', 'order.place', 'RuntimeException', 'boom', self::FAILED_AT, str_repeat('é', 3000), [ErrorContext::EXCEPTION_STACKTRACE => str_repeat('#', 5000)]);

        $recent = $this->requestSnapshot()['deadLetters']['recent'][0];

        self::assertLessThanOrEqual(4096, strlen($recent['payload']));
        self::assertStringEndsWith('... [truncated]', $recent['payload']);
        self::assertStringStartsWith('éééé', $recent['payload']);
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
        $this->deadLetter('m-1', 'App\\PlaceOrder', 'RuntimeException', 'boom', self::FAILED_AT);
        $this->deadLetter('m-2', 'App\\PlaceOrder', 'RuntimeException', 'boom', self::FAILED_AT + 1, headers: [DistributedBusHeader::DISTRIBUTED_ROUTING_KEY => 'order.place']);

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
        self::assertNull($entry['channel']);
        self::assertNull($entry['contentType']);
    }

    #[DataProvider('implementations')]
    public function test_an_empty_dead_letter_reports_an_empty_section(string $implementation): void
    {
        $this->bootstrap($implementation);

        self::assertSame(['total' => 0, 'groups' => [], 'recent' => []], $this->requestSnapshot()['deadLetters']);
    }

    public function test_the_snapshot_has_no_dead_letters_section_when_the_dead_letter_is_disabled(): void
    {
        $this->bootstrap('in-memory', DbalConfiguration::createWithDefaults()->withDeadLetter(false));

        self::assertArrayNotHasKey('deadLetters', $this->requestSnapshot());
    }

    private function bootstrap(string $implementation, ?DbalConfiguration $dbalConfiguration = null): void
    {
        $this->handled = new class () {
            public array $payloads = [];

            #[InternalHandler('order.replayed')]
            public function replayed(string $payload): void
            {
                $this->payloads[] = $payload;
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
        $channels = [SimpleMessageChannelBuilder::createQueueChannel('order_channel'), SimpleMessageChannelBuilder::createQueueChannel('pulse_channel')];
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->handled::class],
            containerOrAvailableServices: [$this->handled, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
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
                    MessageHeaders::TYPE_ID => $messageName,
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

    private function dropDeadLetterTable(): void
    {
        $connection = $this->getConnection();
        if (self::checkIfTableExists($connection, DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE)) {
            $connection->executeStatement('DROP TABLE ' . DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE);
        }
    }
}
