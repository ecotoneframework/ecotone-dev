<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
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
        self::assertSame(['first', 'second'], $handler->batches[0]->payloads);
        self::assertSame(2, $handler->batches[0]->size);
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
        self::assertSame([['tenant' => 'one', 'value' => 1], ['tenant' => 'one', 'value' => 3]], $handler->batches[0]->payloads);
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
        self::assertSame(['first'], $handler->batches[0]->payloads);
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
        self::assertSame(['first'], $handler->batches[0]->payloads);
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
        self::assertSame([['tenant' => 'one']], $handler->batches[0]->payloads);
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
        self::assertSame(['first', 'second'], $handler->batches[0]->payloads);
        self::assertSame(['third', 'fourth'], $handler->batches[1]->payloads);
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
        self::assertSame(['third', 'fourth'], $handler->batches[1]->payloads);
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
        self::assertSame(['first', 'second'], $handler->batches[0]->payloads);
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
                return $batch->payloads;
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
                $this->attempts[] = $batch->payloads;
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
        self::assertEquals([new CollectedEventForConformance('one', 'first'), new CollectedEventForConformance('one', 'second')], $handler->batches[0]->payloads);
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
        self::assertEquals([new CollectedEventForConformance('one', 'first'), new CollectedEventForConformance('one', 'second')], $handler->batches[0]->payloads);
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
