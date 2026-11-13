<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\CollectedReleaseReason;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
            public function collect(mixed $batch): void
            {
                $this->batches[] = $batch;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        foreach ($ecotone->getGateway(GatheredGroups::class)->listOpenBatches('collect.handler') as $batch) {
            $ecotone->getGateway(GatheredGroups::class)->discard($batch['groupId']);
        }

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
            public function collect(mixed $batch): void
            {
                $this->batches[] = $batch;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        foreach ($ecotone->getGateway(GatheredGroups::class)->listOpenBatches('collect.handler') as $batch) {
            $ecotone->getGateway(GatheredGroups::class)->discard($batch['groupId']);
        }

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
            public function collect(mixed $batch): void
            {
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        foreach ($ecotone->getGateway(GatheredGroups::class)->listOpenBatches('collect.handler') as $batch) {
            $ecotone->getGateway(GatheredGroups::class)->discard($batch['groupId']);
        }

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
            public function collect(mixed $batch): void
            {
                $this->batches[] = $batch;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        foreach ($ecotone->getGateway(GatheredGroups::class)->listOpenBatches('collect.handler') as $batch) {
            $ecotone->getGateway(GatheredGroups::class)->discard($batch['groupId']);
        }

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
            public function collect(mixed $batch): void
            {
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        foreach ($ecotone->getGateway(GatheredGroups::class)->listOpenBatches('collect.handler') as $batch) {
            $ecotone->getGateway(GatheredGroups::class)->discard($batch['groupId']);
        }

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
            public function collect(mixed $batch): void
            {
                $this->batches[] = $batch;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('batches')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        foreach ($ecotone->getGateway(GatheredGroups::class)->listOpenBatches('collect.handler') as $batch) {
            $ecotone->getGateway(GatheredGroups::class)->discard($batch['groupId']);
        }

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

}
