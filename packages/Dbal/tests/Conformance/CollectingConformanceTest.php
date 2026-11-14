<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\ChannelInterceptor;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\CollectedMessage;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Messaging\CollectedReleaseReason;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class CollectingConformanceTest extends TestCase
{
    public static function implementations(): iterable
    {
        yield 'in_memory' => [false];
        yield 'database' => [true];
    }

    #[DataProvider('implementations')]
    public function test_collected_handler_runs_once_when_the_batch_reaches_max_size(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };

        $ecotone = $this->bootstrap($handler, $durable);

        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->run('batches');
        self::assertSame([], $handler->batches);

        $ecotone->sendCommandWithRouting('collect', 'second');
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        self::assertSame(['first', 'second'], array_column($handler->batches[0]->messages, 'payload'));
        self::assertSame(2, count($handler->batches[0]->messages));
        self::assertNull($handler->batches[0]->groupKey);
    }

    #[DataProvider('implementations')]
    public function test_messages_are_batched_separately_per_group_key(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2, groupBy: 'payload["tenant"]')]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };

        $ecotone = $this->bootstrap($handler, $durable);

        $ecotone->sendCommandWithRouting('collect', ['tenant' => 'one', 'value' => 1]);
        $ecotone->sendCommandWithRouting('collect', ['tenant' => 'two', 'value' => 2]);
        $ecotone->run('batches');
        self::assertSame([], $handler->batches);

        $ecotone->sendCommandWithRouting('collect', ['tenant' => 'one', 'value' => 3]);
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        self::assertSame('one', $handler->batches[0]->groupKey);
        self::assertSame([['tenant' => 'one', 'value' => 1], ['tenant' => 'one', 'value' => 3]], array_column($handler->batches[0]->messages, 'payload'));
    }

    #[DataProvider('implementations')]
    public function test_message_without_a_group_key_is_refused(bool $durable): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2, groupBy: 'payload["tenant"]')]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };

        $ecotone = $this->bootstrap($handler, $durable);

        $ecotone->sendCommandWithRouting('collect', ['tenant' => null]);
        $this->expectExceptionMessage("Collected handler 'collect.handler' groupBy 'payload[\"tenant\"]' must return a non-null scalar or stringable key. Correct groupBy or the message payload/headers, or remove groupBy to collect every message together.");
        $ecotone->run('batches');
    }

    #[DataProvider('implementations')]
    public function test_collected_handler_runs_with_a_partial_batch_after_max_wait_from_the_first_message(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 3, maxWait: new TimeSpan(seconds: 10))]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };

        $ecotone = $this->bootstrap($handler, $durable);

        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->run('batches');
        self::assertSame([], $handler->batches);

        $ecotone->advanceTimeBy(new TimeSpan(seconds: 9));
        $ecotone->run('batches');
        self::assertSame([], $handler->batches);

        $ecotone->advanceTimeBy(new TimeSpan(seconds: 1));
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        self::assertSame(['first'], array_column($handler->batches[0]->messages, 'payload'));
        self::assertSame(CollectedReleaseReason::MAX_WAIT, $handler->batches[0]->releaseReason);
    }

    #[DataProvider('implementations')]
    public function test_open_batches_are_listed_by_collected_endpoint_id(bool $durable): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2, groupBy: 'payload["tenant"]')]
            #[Asynchronous('batches')]
            #[CommandHandler('collect.listing', endpointId: 'listing.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };

        $ecotone = $this->bootstrap($handler, $durable);

        $groups = $ecotone->getGateway(GatheredGroups::class);
        foreach ($groups->listOpenBatches('listing.handler') as $batch) {
            $groups->discard($batch['groupId']);
        }
        $ecotone->sendCommandWithRouting('collect.listing', ['tenant' => 'one']);
        $ecotone->run('batches');

        $batches = $groups->listOpenBatches('listing.handler');
        self::assertCount(1, $batches);
        self::assertSame('one', $batches[0]['groupKey']);
        self::assertSame('listing.handler', $batches[0]['endpointId']);
        self::assertSame(2, $batches[0]['size']);
        self::assertSame([], $groups->listOpenBatches('another.handler'));
    }

    #[DataProvider('implementations')]
    public function test_max_wait_only_collects_a_batch_without_a_known_size(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxWait: new TimeSpan(seconds: 10))]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };

        $ecotone = $this->bootstrap($handler, $durable);

        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->run('batches');
        self::assertSame([], $handler->batches);

        $ecotone->advanceTimeBy(new TimeSpan(seconds: 9));
        $ecotone->run('batches');
        self::assertSame([], $handler->batches);

        $ecotone->advanceTimeBy(new TimeSpan(seconds: 1));
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        self::assertSame(['first'], array_column($handler->batches[0]->messages, 'payload'));
        self::assertSame(CollectedReleaseReason::MAX_WAIT, $handler->batches[0]->releaseReason);
    }



    #[DataProvider('implementations')]
    public function test_open_batch_can_be_released_by_hand_without_collecting_the_release_message(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 3, groupBy: 'payload["tenant"]')]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', ['tenant' => 'one']);
        $ecotone->run('batches');
        $groups = $ecotone->getGateway(GatheredGroups::class);
        $groups->requestRelease($groups->listOpenBatches('collect.handler')[0]['groupId']);
        self::assertSame([], $handler->batches);
        $ecotone->run('batches');

        self::assertCount(1, $handler->batches);
        self::assertSame([['tenant' => 'one']], array_column($handler->batches[0]->messages, 'payload'));
        self::assertSame('one', $handler->batches[0]->groupKey);
        self::assertSame(CollectedReleaseReason::MANUAL_RELEASE, $handler->batches[0]->releaseReason);
        self::assertSame([], $groups->listOpenBatches('collect.handler'));
    }


    #[DataProvider('implementations')]
    public function test_released_batches_are_listed_by_collected_endpoint_id(bool $durable): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->run('batches');
        $groups = $ecotone->getGateway(GatheredGroups::class);
        $groupId = $groups->listOpenBatches('collect.handler')[0]['groupId'];
        $ecotone->sendCommandWithRouting('collect', 'second');
        $ecotone->run('batches');

        $closed = array_values(array_filter($groups->listClosedBatches('collect.handler'), static fn (array $batch): bool => $batch['groupId'] === $groupId));
        self::assertCount(1, $closed);
        self::assertSame('released', $closed[0]['state']);
        self::assertSame('collect.handler', $closed[0]['endpointId']);
        self::assertSame([], $groups->listClosedBatches('another.handler'));
    }


    #[DataProvider('implementations')]
    public function test_message_after_a_release_opens_a_new_batch(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->sendCommandWithRouting('collect', 'second');
        $ecotone->run('batches');
        $ecotone->sendCommandWithRouting('collect', 'third');
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        $ecotone->sendCommandWithRouting('collect', 'fourth');
        $ecotone->run('batches');
        self::assertCount(2, $handler->batches);
        self::assertSame(['first', 'second'], array_column($handler->batches[0]->messages, 'payload'));
        self::assertSame(['third', 'fourth'], array_column($handler->batches[1]->messages, 'payload'));
    }

    #[DataProvider('implementations')]
    public function test_redelivered_message_is_not_collected_twice_even_after_its_batch_is_released(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $messageId = bin2hex(random_bytes(16));
        $ecotone->sendCommandWithRouting('collect', 'first', metadata: [MessageHeaders::MESSAGE_ID => $messageId]);
        $ecotone->sendCommandWithRouting('collect', 'first', metadata: [MessageHeaders::MESSAGE_ID => $messageId]);
        $ecotone->run('batches');
        self::assertSame([], $handler->batches);
        $ecotone->sendCommandWithRouting('collect', 'second');
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        $ecotone->sendCommandWithRouting('collect', 'first', metadata: [MessageHeaders::MESSAGE_ID => $messageId]);
        $ecotone->sendCommandWithRouting('collect', 'third');
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        $ecotone->sendCommandWithRouting('collect', 'fourth');
        $ecotone->run('batches');
        self::assertSame(['third', 'fourth'], array_column($handler->batches[1]->messages, 'payload'));
    }

    #[DataProvider('implementations')]
    public function test_later_messages_do_not_postpone_the_release(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxWait: new TimeSpan(seconds: 10))]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->run('batches');
        $ecotone->advanceTimeBy(new TimeSpan(seconds: 9));
        $ecotone->sendCommandWithRouting('collect', 'second');
        $ecotone->run('batches');
        self::assertSame([], $handler->batches);
        $ecotone->advanceTimeBy(new TimeSpan(seconds: 1));
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        self::assertSame(['first', 'second'], array_column($handler->batches[0]->messages, 'payload'));
        self::assertSame('2026-01-01 12:00:10', $ecotone->now()->format('Y-m-d H:i:s'));
    }


    #[DataProvider('implementations')]
    public function test_return_value_flows_once_per_batch(bool $durable): void
    {
        $handler = new class () {
            public array $results = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler', outputChannelName: 'collected.result')]
            public function collect(CollectedMessages $batch): array
            {
                return array_column($batch->messages, 'payload');
            }

            #[InternalHandler('collected.result')]
            public function result(array $payloads): void
            {
                $this->results[] = $payloads;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->run('batches');
        self::assertSame([], $handler->results);
        $ecotone->sendCommandWithRouting('collect', 'second');
        $ecotone->run('batches');
        self::assertSame([['first', 'second']], $handler->results);
    }

    #[DataProvider('implementations')]
    public function test_failing_collected_handler_is_retried_with_the_same_batch(bool $durable): void
    {
        $handler = new class () {
            public array $attempts = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->attempts[] = array_column($batch->messages, 'payload');
                if (count($this->attempts) === 1) {
                    throw new RuntimeException('temporarily unavailable');
                }
            }
        };
        $ecotone = $this->bootstrap($handler, $durable, false);
        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->run('batches');
        $ecotone->sendCommandWithRouting('collect', 'second');
        try {
            $ecotone->run('batches');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('temporarily unavailable', $failure->getMessage());
        }
        self::assertSame([['first', 'second']], $handler->attempts);
        $ecotone->run('batches');
        self::assertSame([['first', 'second'], ['first', 'second']], $handler->attempts);
        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenBatches('collect.handler'));
    }


    #[RequiresPhp('>= 8.5.0')]
    #[DataProvider('implementations')]
    public function test_group_key_can_be_a_closure_and_its_payload_type_deserializes_each_message(bool $durable): void
    {
        $handler = require __DIR__ . '/CollectingClosureHandler.php';
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', 'one:first', metadata: ['tenant' => 'header']);
        $ecotone->sendCommandWithRouting('collect', 'one:second', metadata: ['tenant' => 'header']);
        $ecotone->run('batches');

        self::assertCount(1, $handler->batches);
        self::assertSame('header:one', $handler->batches[0]->groupKey);
        self::assertEquals([new CollectedEventForConformance('one', 'first'), new CollectedEventForConformance('one', 'second')], array_column($handler->batches[0]->messages, 'payload'));
    }

    #[RequiresPhp('>= 8.5.0')]
    #[DataProvider('implementations')]
    public function test_group_key_closure_resolves_an_explicit_payload_after_a_header(bool $durable): void
    {
        $handler = require __DIR__ . '/CollectingClosureHandler.php';
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect.explicit', 'one:first', metadata: ['tenant' => 'header']);
        $ecotone->sendCommandWithRouting('collect.explicit', 'one:second', metadata: ['tenant' => 'header']);
        $ecotone->run('batches');

        self::assertCount(1, $handler->batches);
        self::assertSame('header:one', $handler->batches[0]->groupKey);
        self::assertEquals([new CollectedEventForConformance('one', 'first'), new CollectedEventForConformance('one', 'second')], array_column($handler->batches[0]->messages, 'payload'));
    }


    #[DataProvider('implementations')]
    public function test_batch_console_listing_uses_endpoint_id_and_hides_an_unknown_size(bool $durable): void
    {
        $handler = new class () {
            #[Collected(maxWait: new TimeSpan(seconds: 10), groupBy: 'payload["tenant"]')]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', ['tenant' => 'one']);
        $ecotone->run('batches');
        $batch = $ecotone->getGateway(GatheredGroups::class)->listOpenBatches('collect.handler')[0];
        self::assertNull($batch['size']);

        $result = $ecotone->getServiceFromContainer(ConfiguredMessagingSystem::class)->runConsoleCommand('ecotone:collecting:list', ['endpointId' => 'collect.handler']);
        self::assertSame(['Group id', 'Endpoint id', 'Group key', 'State', 'Size', 'Recorded messages', 'Deadline'], $result->columnHeaders);
        self::assertCount(1, $result->rows);
        self::assertSame([$batch['groupId'], 'collect.handler', 'one', 'open', '', 1, '2026-01-01T12:00:10+00:00'], $result->rows[0]);
    }


    #[DataProvider('implementations')]
    public function test_collected_command_accepts_ordinary_class_routed_messages(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler(routingKey: CollectedEventForConformance::class, endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommand(new CollectedEventForConformance('one', 'first'));
        $ecotone->run('batches');
        self::assertSame([], $handler->batches);
        $ecotone->sendCommand(new CollectedEventForConformance('one', 'second'));
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        self::assertEquals([new CollectedEventForConformance('one', 'first'), new CollectedEventForConformance('one', 'second')], array_column($handler->batches[0]->messages, 'payload'));
    }

    #[DataProvider('implementations')]
    public function test_collected_internal_handler_accepts_a_previous_handlers_output(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[CommandHandler('collect.start', outputChannelName: 'collect.internal')]
            public function start(string $payload): string
            {
                return $payload;
            }

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[InternalHandler('collect.internal', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect.start', 'first');
        $ecotone->sendCommandWithRouting('collect.start', 'second');
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        self::assertSame(['first', 'second'], array_column($handler->batches[0]->messages, 'payload'));
    }

    #[DataProvider('implementations')]
    public function test_two_collected_handlers_of_one_event_keep_separate_batches(bool $durable): void
    {
        $handler = new class () {
            public array $first = [];
            public array $second = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[EventHandler(listenTo: CollectedEventForConformance::class, endpointId: 'collect.event.first')]
            public function first(CollectedMessages $batch): void
            {
                $this->first[] = $batch;
            }

            #[Collected(maxSize: 3)]
            #[Asynchronous('batches')]
            #[EventHandler(listenTo: CollectedEventForConformance::class, endpointId: 'collect.event.second')]
            public function second(CollectedMessages $batch): void
            {
                $this->second[] = $batch;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        foreach (['collect.event.first', 'collect.event.second'] as $endpointId) {
            foreach ($ecotone->getGateway(GatheredGroups::class)->listOpenBatches($endpointId) as $batch) {
                $ecotone->getGateway(GatheredGroups::class)->discard($batch['groupId']);
            }
        }
        $ecotone->publishEvent(new CollectedEventForConformance('one', 'first'));
        $ecotone->publishEvent(new CollectedEventForConformance('one', 'second'));
        $ecotone->run('batches');
        self::assertCount(1, $handler->first);
        self::assertSame([], $handler->second);
        $ecotone->publishEvent(new CollectedEventForConformance('one', 'third'));
        $ecotone->run('batches');
        self::assertCount(1, $handler->first);
        self::assertCount(1, $handler->second);
        self::assertSame(2, count($handler->first[0]->messages));
        self::assertSame(3, count($handler->second[0]->messages));
    }


    #[DataProvider('implementations')]
    public function test_group_listings_remain_separate_from_collected_batch_listings(bool $durable): void
    {
        $handler = new class () {
            #[Collected(maxWait: new TimeSpan(seconds: 10))]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->run('batches');
        $groups = $ecotone->getGateway(GatheredGroups::class);
        $batch = $groups->listOpenBatches('collect.handler')[0];
        self::assertNull($batch['size']);
        self::assertSame([], $groups->listOpenGroups($batch['gatherChannelName']));
        $groups->requestRelease($batch['groupId']);
        $ecotone->run('batches');
        self::assertSame([], $groups->listClosedGroups($batch['gatherChannelName']));
    }


    #[DataProvider('implementations')]
    public function test_batch_inherits_the_first_messages_user_headers_and_retains_each_messages_own_headers(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];
            public array $headers = [];
            public array $labels = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch, #[Headers] array $headers, #[Header('label')] ?string $label = null): void
            {
                $this->batches[] = $batch;
                $this->headers[] = $headers;
                $this->labels[] = $label;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', 'first', metadata: ['label' => 'first-label']);
        $ecotone->sendCommandWithRouting('collect', 'second', metadata: ['label' => 'second-label']);
        $ecotone->run('batches');

        self::assertSame('first-label', $handler->batches[0]->messages[0]->headers['label']);
        self::assertSame('second-label', $handler->batches[0]->messages[1]->headers['label']);
        self::assertSame('first-label', $handler->headers[0]['label'] ?? null);
        self::assertSame(['first-label'], $handler->labels);
        self::assertArrayHasKey(MessageHeaders::MESSAGE_ID, $handler->headers[0]);
    }


    #[DataProvider('implementations')]
    public function test_timeout_of_a_released_batch_does_not_release_the_next_batch(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2, maxWait: new TimeSpan(seconds: 10))]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->sendCommandWithRouting('collect', 'second');
        $ecotone->run('batches');
        self::assertSame(CollectedReleaseReason::SIZE_REACHED, $handler->batches[0]->releaseReason);
        $ecotone->advanceTimeBy(new TimeSpan(seconds: 9));
        $ecotone->sendCommandWithRouting('collect', 'third');
        $ecotone->run('batches');
        $ecotone->advanceTimeBy(new TimeSpan(seconds: 1));
        $ecotone->run('batches');
        self::assertCount(1, $handler->batches);
        $ecotone->advanceTimeBy(new TimeSpan(seconds: 9));
        $ecotone->run('batches');
        self::assertCount(2, $handler->batches);
        self::assertSame(['third'], array_column($handler->batches[1]->messages, 'payload'));
        self::assertSame(CollectedReleaseReason::MAX_WAIT, $handler->batches[1]->releaseReason);
    }

    #[DataProvider('implementations')]
    public function test_max_size_only_batch_waits_until_full_or_released_by_hand(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', 'first');
        $ecotone->run('batches');
        $ecotone->advanceTimeBy(new TimeSpan(days: 30));
        $ecotone->run('batches');
        self::assertSame([], $handler->batches);
        $groups = $ecotone->getGateway(GatheredGroups::class);
        $batch = $groups->listOpenBatches('collect.handler')[0];
        self::assertNull($batch['deadline']);
        $groups->requestRelease($batch['groupId']);
        $ecotone->run('batches');
        self::assertSame(['first'], array_column($handler->batches[0]->messages, 'payload'));
        self::assertSame(CollectedReleaseReason::MANUAL_RELEASE, $handler->batches[0]->releaseReason);
    }

    #[DataProvider('implementations')]
    public function test_failed_timeout_send_rolls_back_opening_the_batch(bool $durable): void
    {
        if (! $durable) {
            self::markTestSkipped('The test-only in-memory store has no transaction: a deferred channel send fails after the store returned.');
        }
        $handler = new class () {
            public bool $failTimeout = true;
            public array $batches = [];

            #[ChannelInterceptor('batches')]
            public function sending(#[Headers] array $headers): void
            {
                if ($this->failTimeout && isset($headers[MessageHeaders::DELIVERY_DELAY])) {
                    throw new RuntimeException('timeout transport unavailable');
                }
            }

            #[Collected(maxWait: new TimeSpan(seconds: 10))]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable, false);
        $ecotone->sendCommandWithRouting('collect', 'first');
        try {
            $ecotone->run('batches');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('timeout transport unavailable', $failure->getMessage());
        }
        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenBatches('collect.handler'));
        $handler->failTimeout = false;
        $ecotone->run('batches');
        $ecotone->advanceTimeBy(new TimeSpan(seconds: 10));
        $ecotone->run('batches');
        self::assertSame(['first'], array_column($handler->batches[0]->messages, 'payload'));
    }

    #[DataProvider('implementations')]
    public function test_batch_keeps_the_order_of_recording(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 3)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        foreach (['third', 'first', 'second'] as $payload) {
            $ecotone->sendCommandWithRouting('collect', $payload, metadata: ['ordinal' => $payload]);
            $ecotone->run('batches');
        }
        self::assertSame(['third', 'first', 'second'], array_column($handler->batches[0]->messages, 'payload'));
        self::assertSame(['third', 'first', 'second'], array_column(array_column($handler->batches[0]->messages, 'headers'), 'ordinal'));
    }

    #[DataProvider('implementations')]
    public function test_closed_batches_follow_gathering_retention_and_open_batches_are_kept(bool $durable): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2, groupBy: 'payload["key"]')]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $groups = $ecotone->getGateway(GatheredGroups::class);
        $before = array_column($groups->listClosedBatches('collect.handler'), 'groupId');
        $ecotone->sendCommandWithRouting('collect', ['key' => 'closed']);
        $ecotone->sendCommandWithRouting('collect', ['key' => 'closed']);
        $ecotone->sendCommandWithRouting('collect', ['key' => 'open']);
        $ecotone->run('batches');
        $closedId = array_values(array_diff(array_column($groups->listClosedBatches('collect.handler'), 'groupId'), $before))[0];
        $openId = $groups->listOpenBatches('collect.handler')[0]['groupId'];
        $ecotone->advanceTimeBy(new TimeSpan(days: 6));
        $groups->cleanupClosedGroups();
        self::assertContains($closedId, array_column($groups->listClosedBatches('collect.handler'), 'groupId'));
        $ecotone->advanceTimeBy(new TimeSpan(days: 1));
        $groups->cleanupClosedGroups();
        self::assertNotContains($closedId, array_column($groups->listClosedBatches('collect.handler'), 'groupId'));
        self::assertContains($openId, array_column($groups->listOpenBatches('collect.handler'), 'groupId'));
    }

    #[DataProvider('implementations')]
    public function test_non_scalar_group_key_is_refused_without_opening_a_fallback_batch(bool $durable): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2, groupBy: 'payload["tenant"]')]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', ['tenant' => ['one', 'two']]);
        $failure = null;
        try {
            $ecotone->run('batches');
        } catch (Throwable $caught) {
            $failure = $caught;
        }
        self::assertNotNull($failure);
        self::assertStringContainsString("Collected handler 'collect.handler' groupBy 'payload[\"tenant\"]'", $failure->getMessage());
        self::assertStringContainsString('non-null scalar or stringable key', $failure->getMessage());
        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenBatches('collect.handler'));
    }

    #[DataProvider('implementations')]
    public function test_failing_group_expression_names_the_handler_and_expression_without_opening_a_batch(bool $durable): void
    {
        $handler = new class () {
            #[Collected(maxSize: 2, groupBy: 'payload.missingMethod()')]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', 'ordinary payload');
        $failure = null;
        try {
            $ecotone->run('batches');
        } catch (Throwable $caught) {
            $failure = $caught;
        }
        self::assertNotNull($failure);
        self::assertStringContainsString($handler::class . '::collect', $failure->getMessage());
        self::assertStringContainsString('payload.missingMethod()', $failure->getMessage());
        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenBatches('collect.handler'));
    }

    public function test_max_wait_timer_keeps_the_first_messages_tenant_for_its_database_connection(): void
    {
        $handler = new class () {
            public array $batches = [];
            public array $tenants = [];

            #[Collected(maxWait: new TimeSpan(seconds: 10), groupBy: 'payload["key"]')]
            #[Asynchronous('tenant_batches')]
            #[CommandHandler('collect.tenant', endpointId: 'collect.tenant.handler')]
            public function collect(CollectedMessages $batch, #[Header('tenant')] ?string $tenant = null): void
            {
                $this->batches[] = $batch;
                $this->tenants[] = $tenant;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, 'collecting_tenant_connection' => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: [
                MultiTenantConfiguration::create('tenant', ['collecting_tenant' => 'collecting_tenant_connection']),
                SimpleMessageChannelBuilder::createQueueChannel('tenant_batches'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false)->withDefaultInstantRetries(false),
        );
        $ecotone->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);
        $key = bin2hex(random_bytes(16));
        $ecotone->sendCommandWithRouting('collect.tenant', ['key' => $key, 'value' => 'first'], metadata: ['tenant' => 'collecting_tenant']);
        $ecotone->run('tenant_batches');
        self::assertSame([], $handler->batches);
        $ecotone->advanceTimeBy(new TimeSpan(seconds: 10));
        $failure = null;
        try {
            $ecotone->run('tenant_batches');
        } catch (Throwable $caught) {
            $failure = $caught->getMessage();
        }
        self::assertNull($failure);
        self::assertCount(1, $handler->batches);
        self::assertSame([['key' => $key, 'value' => 'first']], array_column($handler->batches[0]->messages, 'payload'));
        self::assertSame('collecting_tenant', $handler->batches[0]->messages[0]->headers['tenant']);
        self::assertSame(['collecting_tenant'], $handler->tenants);
    }

    #[DataProvider('implementations')]
    public function test_batch_contains_ordered_message_objects_with_their_own_payload_and_headers(bool $durable): void
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $ecotone->sendCommandWithRouting('collect', 'first', metadata: ['label' => 'one']);
        $ecotone->sendCommandWithRouting('collect', 'second', metadata: ['label' => 'two']);
        $ecotone->run('batches');
        $batch = $handler->batches[0];
        self::assertObjectHasProperty('messages', $batch);
        self::assertCount(2, $batch->messages);
        self::assertInstanceOf(CollectedMessage::class, $batch->messages[0]);
        self::assertSame('first', $batch->messages[0]->payload);
        self::assertSame('one', $batch->messages[0]->headers['label']);
        self::assertSame('second', $batch->messages[1]->payload);
        self::assertSame('two', $batch->messages[1]->headers['label']);
    }

    #[DataProvider('implementations')]
    public function test_first_messages_delivery_headers_do_not_cross_the_batch_or_output_boundary(bool $durable): void
    {
        $handler = new class () {
            public array $batchHeaders = [];
            public array $outputHeaders = [];
            public array $messages = [];

            #[Collected(maxSize: 2)]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'collect.handler', outputChannelName: 'batch.output')]
            public function collect(CollectedMessages $batch, #[Headers] array $headers): string
            {
                $this->batchHeaders[] = $headers;
                $this->messages = $batch->messages;
                return 'completed';
            }

            #[InternalHandler('batch.output', endpointId: 'batch.output.handler')]
            public function output(string $result, #[Headers] array $headers): void
            {
                $this->outputHeaders[] = $headers;
            }
        };
        $ecotone = $this->bootstrap($handler, $durable);
        $originalId = bin2hex(random_bytes(16));
        $ecotone->sendCommandWithRouting('collect', 'first', metadata: [
            MessageHeaders::MESSAGE_ID => $originalId,
            MessageHeaders::DELIVERY_DELAY => 1000,
            MessageHeaders::TIME_TO_LIVE => 60000,
            MessageHeaders::PRIORITY => 5,
            'label' => 'first',
        ]);
        $ecotone->advanceTimeBy(new TimeSpan(seconds: 1));
        $ecotone->run('batches');
        $ecotone->sendCommandWithRouting('collect', 'second', metadata: ['label' => 'second']);
        $ecotone->run('batches');
        self::assertCount(1, $handler->batchHeaders);
        self::assertCount(1, $handler->outputHeaders);
        self::assertSame($originalId, $handler->messages[0]->headers[MessageHeaders::MESSAGE_ID]);
        foreach ([$handler->batchHeaders[0], $handler->outputHeaders[0]] as $headers) {
            self::assertSame('first', $headers['label']);
            self::assertNotSame($originalId, $headers[MessageHeaders::MESSAGE_ID]);
            foreach ([MessageHeaders::ROUTING_SLIP, MessageHeaders::DELIVERY_DELAY, MessageHeaders::TIME_TO_LIVE, MessageHeaders::PRIORITY, MessageHeaders::REPLY_CHANNEL] as $excluded) {
                self::assertArrayNotHasKey($excluded, $headers);
            }
        }
    }

    private function bootstrap(object $handler, bool $durable, bool $instantRetries = true): FlowTestSupport
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries($instantRetries),
        );
        foreach ($ecotone->getGateway(GatheredGroups::class)->listOpenBatches('collect.handler') as $batch) {
            $ecotone->getGateway(GatheredGroups::class)->discard($batch['groupId']);
        }
        return $ecotone;
    }
}

final class CollectedEventForConformance
{
    public function __construct(public readonly string $tenant, public readonly string $value)
    {
    }
}
