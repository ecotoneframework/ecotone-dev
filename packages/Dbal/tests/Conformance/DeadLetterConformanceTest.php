<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DeadLetterConformanceTest extends DbalMessagingTestCase
{
    public const REPLAYED_CHANNEL = 'deadLetterConformance.replayed';

    private object $replayed;

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('implementations')]
    public function test_a_stored_message_is_shown_with_its_payload_and_headers(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        $deadLetter->store($this->failedMessage('m-1', 'order coffee', 1_700_000_000, ['customer' => 'c-7']));

        $shown = $deadLetter->show('m-1');

        self::assertSame('order coffee', $shown->getPayload());
        self::assertSame('m-1', $shown->getHeaders()->getMessageId());
        self::assertSame('c-7', $shown->getHeaders()->get('customer'));
        self::assertSame('failed order coffee', $shown->getHeaders()->get(ErrorContext::EXCEPTION_MESSAGE));
    }

    #[DataProvider('implementations')]
    public function test_nothing_stored_counts_and_lists_as_empty(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        self::assertSame(0, $deadLetter->count());
        self::assertSame([], $deadLetter->list(10, 0));
    }

    #[DataProvider('implementations')]
    public function test_messages_are_listed_most_recently_failed_first(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        $deadLetter->store($this->failedMessage('m-2', 'second', 1_700_000_200));
        $deadLetter->store($this->failedMessage('m-1', 'first', 1_700_000_100));
        $deadLetter->store($this->failedMessage('m-3', 'third', 1_700_000_300));

        self::assertSame(['m-3', 'm-2', 'm-1'], $this->messageIdsOf($deadLetter->list(10, 0)));
        self::assertSame(3, $deadLetter->count());
    }

    #[DataProvider('implementations')]
    public function test_messages_that_failed_at_the_same_time_are_listed_by_message_id(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        $deadLetter->store($this->failedMessage('m-b', 'b', 1_700_000_100));
        $deadLetter->store($this->failedMessage('m-c', 'c', 1_700_000_100));
        $deadLetter->store($this->failedMessage('m-a', 'a', 1_700_000_100));
        $deadLetter->store($this->failedMessage('m-d', 'd', 1_700_000_200));

        self::assertSame(['m-d', 'm-a', 'm-b', 'm-c'], $this->messageIdsOf($deadLetter->list(10, 0)));
    }

    #[DataProvider('implementations')]
    public function test_a_limit_caps_the_page_and_an_offset_skips_that_many_of_the_most_recent(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        foreach (['m-1', 'm-2', 'm-3', 'm-4', 'm-5'] as $position => $messageId) {
            $deadLetter->store($this->failedMessage($messageId, $messageId, 1_700_000_000 + $position));
        }

        self::assertSame(['m-5', 'm-4'], $this->messageIdsOf($deadLetter->list(2, 0)));
        self::assertSame(['m-3', 'm-2'], $this->messageIdsOf($deadLetter->list(2, 2)));
        self::assertSame(['m-1'], $this->messageIdsOf($deadLetter->list(2, 4)));
        self::assertSame([], $this->messageIdsOf($deadLetter->list(2, 6)));
    }

    #[DataProvider('implementations')]
    public function test_consecutive_pages_over_messages_that_failed_at_the_same_time_neither_repeat_nor_skip_one(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        foreach (['m-e', 'm-a', 'm-d', 'm-b', 'm-c'] as $messageId) {
            $deadLetter->store($this->failedMessage($messageId, $messageId, 1_700_000_000));
        }

        self::assertSame(
            ['m-a', 'm-b', 'm-c', 'm-d', 'm-e'],
            [...$this->messageIdsOf($deadLetter->list(2, 0)), ...$this->messageIdsOf($deadLetter->list(2, 2)), ...$this->messageIdsOf($deadLetter->list(2, 4))],
        );
    }

    #[DataProvider('implementations')]
    public function test_a_listed_message_carries_its_error_context(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        $deadLetter->store($this->failedMessage('m-1', 'order coffee', 1_700_000_000));

        $errorContext = $deadLetter->list(1, 0)[0];

        self::assertSame('m-1', $errorContext->getMessageId());
        self::assertSame(1_700_000_000, $errorContext->getFailedTimestamp());
        self::assertSame('failed order coffee', $errorContext->getMessage());
    }

    #[DataProvider('implementations')]
    public function test_storing_a_message_id_that_is_already_stored_keeps_both(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        $deadLetter->store($this->failedMessage('m-1', 'first handler', 1_700_000_000));
        $deadLetter->store($this->failedMessage('m-1', 'second handler', 1_700_000_000));

        self::assertSame(2, $deadLetter->count());
        self::assertSame('first handler', $deadLetter->show('m-1')->getPayload());
    }

    #[DataProvider('implementations')]
    public function test_deleting_a_message_removes_only_that_one(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        $deadLetter->store($this->failedMessage('m-1', 'first', 1_700_000_100));
        $deadLetter->store($this->failedMessage('m-2', 'second', 1_700_000_200));
        $deadLetter->store($this->failedMessage('m-3', 'third', 1_700_000_300));

        $deadLetter->delete('m-2');
        $deadLetter->delete(['m-3', 'm-404']);

        self::assertSame(['m-1'], $this->messageIdsOf($deadLetter->list(10, 0)));
        self::assertSame(1, $deadLetter->count());
    }

    #[DataProvider('implementations')]
    public function test_deleting_all_empties_the_dead_letter(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        $deadLetter->store($this->failedMessage('m-1', 'first', 1_700_000_100));
        $deadLetter->store($this->failedMessage('m-2', 'second', 1_700_000_200));

        $deadLetter->deleteAll();

        self::assertSame(0, $deadLetter->count());
        self::assertSame([], $deadLetter->list(10, 0));
    }

    #[DataProvider('implementations')]
    public function test_showing_a_message_that_is_not_stored_names_where_to_look(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        try {
            $deadLetter->show('m-404');
            self::fail('Expected a message that is not stored to be refused');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('The dead letter holds no message with id m-404', $exception->getMessage());
            self::assertStringContainsString('DeadLetterGateway::list()', $exception->getMessage());
        }
    }

    #[DataProvider('implementations')]
    public function test_replaying_a_message_sends_it_back_and_removes_it(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        $deadLetter->store($this->failedMessage('m-1', 'order coffee', 1_700_000_100, [MessageHeaders::ROUTING_SLIP => self::REPLAYED_CHANNEL]));
        $deadLetter->store($this->failedMessage('m-2', 'order tea', 1_700_000_200, [MessageHeaders::ROUTING_SLIP => self::REPLAYED_CHANNEL]));

        $deadLetter->replay('m-1');

        self::assertSame(['order coffee'], $this->replayed->payloads);
        self::assertSame(['m-2'], $this->messageIdsOf($deadLetter->list(10, 0)));
    }

    #[DataProvider('implementations')]
    public function test_replaying_all_sends_every_message_back_and_empties_the_dead_letter(string $implementation): void
    {
        $deadLetter = $this->bootstrap($implementation);

        $deadLetter->store($this->failedMessage('m-1', 'order coffee', 1_700_000_100, [MessageHeaders::ROUTING_SLIP => self::REPLAYED_CHANNEL]));
        $deadLetter->store($this->failedMessage('m-2', 'order tea', 1_700_000_200, [MessageHeaders::ROUTING_SLIP => self::REPLAYED_CHANNEL]));

        $deadLetter->replayAll();

        self::assertSame(['order tea', 'order coffee'], $this->replayed->payloads);
        self::assertSame(0, $deadLetter->count());
    }

    private function bootstrap(string $implementation): DeadLetterGateway
    {
        $this->replayed = new class () {
            public array $payloads = [];

            #[InternalHandler(DeadLetterConformanceTest::REPLAYED_CHANNEL)]
            public function replayed(string $payload): void
            {
                $this->payloads[] = $payload;
            }
        };

        return $this->bootstrapFlowTesting(
            classesToResolve: [$this->replayed::class],
            containerOrAvailableServices: [$this->replayed, DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withAutomaticTableInitialization(true)
                        ->withDeadLetter(true, inMemory: match ($implementation) {
                            'in-memory' => true,
                            'dbal' => false,
                        }),
                ]),
        )->initializeDatabase()->getGateway(DeadLetterGateway::class);
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function failedMessage(string $messageId, string $payload, int $failedAt, array $headers = []): Message
    {
        return MessageBuilder::withPayload($payload)
            ->setMultipleHeaders([
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::TIMESTAMP => $failedAt,
                ErrorContext::EXCEPTION_MESSAGE => "failed {$payload}",
                ErrorContext::EXCEPTION_STACKTRACE => '#0 handler.php(1)',
                ErrorContext::EXCEPTION_FILE => 'handler.php',
                ErrorContext::EXCEPTION_LINE => 1,
                ErrorContext::EXCEPTION_CODE => 0,
                ...$headers,
            ])
            ->build();
    }

    /**
     * @param ErrorContext[] $errorContexts
     * @return string[]
     */
    private function messageIdsOf(array $errorContexts): array
    {
        return array_map(fn (ErrorContext $errorContext): string => $errorContext->getMessageId(), $errorContexts);
    }
}
