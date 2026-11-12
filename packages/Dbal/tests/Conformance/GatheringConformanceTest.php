<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Orchestrator;
use Ecotone\Api\Attribute\PropagateHeaders;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Attribute\Router;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\GatheringConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Messaging\GatheredReleaseReason;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\TimeSpan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class GatheringConformanceTest extends TestCase
{
    private const ENTERPRISE_LICENCE = 'eyJzaWduYXR1cmUiOiJxRmtrSDdCQkxzdUlTRzExZmVXeHVKVTY3bGZScmFSTFBpb0VUaGdoZTAyYWRjajlVRkNWWXhvaGl4YXhoaEFlNzU5aExOT0R5NXo1T2N2eFppUGQyemRRblYrb1dNZjFwM2lqbFN4cUg1MDhVV1wvOG90TllkM28wMVRZRmdMSjU4RXJzYThLcUJib0Z1MmhaZW9lczQ3elM2VHR5TzFGV2pOeWxlbG1aRWNLUHFydFhzOTNcL1YxY0wwWVg4UFBOZDl6bVRPOWxyc3UzM3F1anBXVlB4eVRMTW4yK3lPc3Jxa2pJZDArSU1LSWl0dG1IZGlBcml6NnZIXC93RnFKTU5Od2JKWnFsUXRNa3pQc3pvRlFseVgyZnNPTG5CTWNCajA5S0VnNjhVcG14OVwvUnBHZXBNV2UzcThYeFZMa3laOEtXdndEN2JBZDZPSXVoejlxRThyamVQVVVsbUxybm0wd3ZFYjV6UVZEbU5OY21uZnNkMEYyazJhSldySzc5VXlEWVY3UFNvVkZiM0diV0VNN2p1STVFV2w5cTVaWXJiOGxFcmxTTmNpYmQwbCttMWkzUWk1TnZhU1RscHBTV1BGS0g4c2x5dDhKRzV4YXZqQlJFU3BJWGVDNE9VREdoZDRJNEhaaEpDXC9Wcm9Nb0FRNzBMaXRrUHcxWkdWQ1dqTURucHdNdWRyWThpMVQwak5MRytXYlpYckpvRHZxd0x4SENKdU9EWnhuYVNISkVVSVFndGViUXZPRURIKzBjN0o0VVJMWkV2NnU2d2tcL1F6TzlPVnVVS1BubDkzK1dVam8xVDZqOEJ0MEtSeHhsMmtBY2pQcE9wWTg5Q25BQlBtckF4THJhYkxXeThpZmxVQ0cwUjNmZzB4UnpiMjlMSENvUW95b2NmRStzYjhFYz0iLCJkYXRhIjp7ImVtYWlsIjoiam9obnlAd3AucGwiLCJleHBpcmVBdCI6IjIwMzItMTAtMTEgMDA6MDA6MDAiLCJpc0VudGVycHJpc2VQbHVzIjpmYWxzZX19';

    public function test_open_group_survives_application_restart(): void
    {
        $handler = new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'work.handler')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $previousGroups = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary');
        $ecotone->sendCommandWithRouting('split', [10, 20]);

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );


        $groups = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary');
        self::assertCount(count($previousGroups) + 1, $groups);
        $groups = array_values(array_filter($groups, static fn (array $group): bool => ! in_array($group['groupId'], array_column($previousGroups, 'groupId'), true)));
        self::assertSame(2, $groups[0]['size']);
        self::assertSame([], $groups[0]['accountedPartNumbers']);
        self::assertSame([1, 2], $groups[0]['missingPartNumbers']);
    }

    #[DataProvider('implementations')]
    public function test_timeout_trigger_outlives_the_split_messages_time_to_live(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered(timeout: new TimeSpan(minutes: 30))]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                $durable ? DbalBackedMessageChannelBuilder::create('summaries') : SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [10, 20], metadata: [MessageHeaders::TIME_TO_LIVE => 60_000]);
        $ecotone->run('workers', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        $ecotone->run('summaries');
        self::assertSame([], $handler->gathered);
        $ecotone->advanceTimeBy(new TimeSpan(minutes: 29));
        $ecotone->run('summaries');
        self::assertSame([], $handler->gathered);
        $ecotone->advanceTimeBy(new TimeSpan(minutes: 1));
        $ecotone->run('summaries');
        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 20], $handler->gathered[0]->results);
        self::assertSame([2], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(2, $handler->gathered[0]->size);
        self::assertSame([10, 20], $handler->gathered[0]->originalPayload);
        self::assertSame(GatheredReleaseReason::TIMEOUT, $handler->gathered[0]->releaseReason);
    }

    #[DataProvider('implementations')]
    public function test_timeout_releases_group_with_missing_parts_reported(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered(timeout: new TimeSpan(minutes: 30))]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                $durable ? DbalBackedMessageChannelBuilder::create('summaries') : SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->run('workers', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        $ecotone->run('summaries');
        self::assertSame([], $handler->gathered);
        $ecotone->advanceTimeBy(new TimeSpan(minutes: 29));
        $ecotone->run('summaries');
        self::assertSame([], $handler->gathered);
        $ecotone->advanceTimeBy(new TimeSpan(minutes: 1));
        $ecotone->run('summaries');
        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 20], $handler->gathered[0]->results);
        self::assertSame([2], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(2, $handler->gathered[0]->size);
        self::assertSame([10, 20], $handler->gathered[0]->originalPayload);
        self::assertSame(GatheredReleaseReason::TIMEOUT, $handler->gathered[0]->releaseReason);
    }

    #[DataProvider('implementations')]
    public function test_late_part_is_dropped_and_noted_on_closed_group(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered(timeout: new TimeSpan(minutes: 30))]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $before = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $groups = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary');
        $groupId = array_values(array_diff(array_column($groups, 'groupId'), array_column($before, 'groupId')))[0];
        $ecotone->advanceTimeBy(new TimeSpan(minutes: 30));
        $ecotone->run('summaries');
        self::assertCount(1, $handler->gathered);
        self::assertSame([1, 2], $handler->gathered[0]->missingPartNumbers);
        $ecotone->run('workers');
        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        $closed = array_values(array_filter($ecotone->getGateway(GatheredGroups::class)->listClosedGroups('timeout.summary'), static fn (array $group): bool => $group['groupId'] === $groupId));
        self::assertCount(1, $closed);
        self::assertSame('released', $closed[0]['state']);
        self::assertSame(2, $closed[0]['latePartCount']);
        self::assertSame([], $closed[0]['accountedPartNumbers']);
    }

    #[DataProvider('implementations')]
    public function test_cleanup_retains_closed_groups_for_seven_days_from_release_and_never_removes_open_groups(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $before = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary');
        $ecotone->sendCommandWithRouting('split', []);
        $groups = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary');
        $closedGroupId = array_values(array_diff(array_column($groups, 'groupId'), array_column($before, 'groupId')))[0];
        $ecotone->advanceTimeBy(new TimeSpan(days: 6));
        $ecotone->run('summaries');
        $ecotone->sendCommandWithRouting('split', [10]);
        $groups = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary');
        $openGroupId = array_values(array_diff(array_column($groups, 'groupId'), array_column($before, 'groupId')))[0];
        $ecotone->advanceTimeBy(new TimeSpan(days: 1));
        $ecotone->runConsoleCommand('ecotone:gathering:cleanup');
        self::assertContains($closedGroupId, array_column($ecotone->getGateway(GatheredGroups::class)->listClosedGroups('timeout.summary'), 'groupId'));
        $ecotone->advanceTimeBy(new TimeSpan(days: 6));
        $ecotone->runConsoleCommand('ecotone:gathering:cleanup');

        self::assertNotContains($closedGroupId, array_column($ecotone->getGateway(GatheredGroups::class)->listClosedGroups('timeout.summary'), 'groupId'));
        self::assertContains($openGroupId, array_column($ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary'), 'groupId'));
    }

    #[DataProvider('implementations')]
    public function test_configured_retention_applies_to_gateway_cleanup(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[ServiceContext]
            public function gathering(): array
            {
                return [GatheringConfiguration::createWithDefaults()->withRetention(new TimeSpan(days: 2))];
            }

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $before = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary');
        $ecotone->sendCommandWithRouting('split', []);
        $groups = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary');
        $groupId = array_values(array_diff(array_column($groups, 'groupId'), array_column($before, 'groupId')))[0];
        $ecotone->run('summaries');
        $ecotone->advanceTimeBy(new TimeSpan(days: 1));
        $ecotone->getGateway(GatheredGroups::class)->cleanupClosedGroups();
        self::assertContains($groupId, array_column($ecotone->getGateway(GatheredGroups::class)->listClosedGroups('timeout.summary'), 'groupId'));
        $ecotone->advanceTimeBy(new TimeSpan(days: 1));
        $ecotone->getGateway(GatheredGroups::class)->cleanupClosedGroups();
        self::assertNotContains($groupId, array_column($ecotone->getGateway(GatheredGroups::class)->listClosedGroups('timeout.summary'), 'groupId'));
    }

    #[DataProvider('implementations')]
    public function test_part_for_cleaned_up_group_is_refused_with_the_retention_setting(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered(timeout: new TimeSpan(minutes: 30))]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $before = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $groups = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary');
        $groupId = array_values(array_diff(array_column($groups, 'groupId'), array_column($before, 'groupId')))[0];
        $ecotone->advanceTimeBy(new TimeSpan(minutes: 30));
        $ecotone->run('summaries');
        self::assertCount(1, $handler->gathered);
        self::assertSame([1, 2], $handler->gathered[0]->missingPartNumbers);
        $ecotone->advanceTimeBy(new TimeSpan(days: 7));
        $ecotone->getGateway(GatheredGroups::class)->cleanupClosedGroups();
        $ecotone->run('workers');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Gathering group '{$groupId}' was not found. Its closed-group retention may have expired. Increase GatheringConfiguration::withRetention(...), or replay failed parts before running ecotone:gathering:cleanup.");
        $ecotone->run('summaries');
    }

    #[DataProvider('implementations')]
    public function test_timeout_deadline_is_measured_from_the_split(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered(timeout: new TimeSpan(minutes: 30))]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $before = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->advanceTimeBy(new TimeSpan(minutes: 29));
        $groups = array_values(array_filter($ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary'), static fn (array $group): bool => ! in_array($group['groupId'], array_column($before, 'groupId'), true)));

        self::assertCount(1, $groups);
        self::assertSame((new DateTimeImmutable('2026-01-01 12:30:00 UTC'))->getTimestamp() * 1000, $groups[0]['deadline']);
    }

    #[DataProvider('implementations')]
    public function test_replayed_split_schedules_only_one_timeout(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];
            public int $summaryDeliveries = 0;

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE - 1, pointcut: AsynchronousRunningEndpoint::class)]
            public function replaySplit(MethodInvocation $invocation, AsynchronousRunningEndpoint $endpoint): mixed
            {
                $again = $invocation->cloneCurrentState();
                if ($endpoint->getEndpointId() === 'summaries') {
                    $this->summaryDeliveries++;
                }
                $result = $invocation->proceed();
                if ($endpoint->getEndpointId() === 'splits') {
                    $again->proceed();
                }

                return $result;
            }

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('splits')]
            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered(timeout: new TimeSpan(minutes: 30))]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                ...($durable ? [DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false)] : []),
                SimpleMessageChannelBuilder::createQueueChannel('splits'),
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->run('splits');
        $ecotone->advanceTimeBy(new TimeSpan(minutes: 30));
        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame(1, $handler->summaryDeliveries);
    }

    public function test_replayed_failed_part_completes_group_without_timeout(): void
    {
        $handler = new class () {
            public array $gathered = [];
            public bool $fail = true;

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('replay_workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                if ($part === 20 && $this->fail) {
                    throw new RuntimeException('Part needs repair');
                }

                return $part * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: [
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'),
                DbalBackedMessageChannelBuilder::create('replay_workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $before = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary');
        $deadLetters = $ecotone->getGateway(DeadLetterGateway::class);
        $previousFailures = array_map(static fn ($failure): string => $failure->getMessageId(), $deadLetters->list(1000, 0));
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->run('replay_workers', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        $ecotone->run('summaries');
        self::assertSame([], $handler->gathered);
        $groups = array_values(array_filter($ecotone->getGateway(GatheredGroups::class)->listOpenGroups('timeout.summary'), static fn (array $group): bool => ! in_array($group['groupId'], array_column($before, 'groupId'), true)));
        self::assertCount(1, $groups);
        self::assertSame([1], $groups[0]['accountedPartNumbers']);
        self::assertSame([2], $groups[0]['missingPartNumbers']);
        $ecotone->advanceTimeBy(new TimeSpan(days: 30));
        $ecotone->run('summaries');
        self::assertSame([], $handler->gathered);
        $failures = array_values(array_filter($deadLetters->list(1000, 0), static fn ($failure): bool => ! in_array($failure->getMessageId(), $previousFailures, true)));
        self::assertCount(1, $failures);
        $handler->fail = false;
        $deadLetters->replay($failures[0]->getMessageId());
        $ecotone->run('replay_workers');
        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 20, 2 => 40], $handler->gathered[0]->results);
        self::assertSame([], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR, $handler->gathered[0]->releaseReason);
    }

    #[DataProvider('implementations')]
    public function test_timeout_after_retention_cleanup_does_not_recreate_the_group(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered(timeout: new TimeSpan(days: 30))]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->run('workers');
        $ecotone->run('summaries');
        self::assertCount(1, $handler->gathered);
        $ecotone->advanceTimeBy(new TimeSpan(days: 7));
        $ecotone->getGateway(GatheredGroups::class)->cleanupClosedGroups();
        $ecotone->advanceTimeBy(new TimeSpan(days: 23));
        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame(GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR, $handler->gathered[0]->releaseReason);
    }

    #[DataProvider('implementations')]
    public function test_manual_release_is_requested_until_its_trigger_is_consumed(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $groups = $ecotone->getGateway(GatheredGroups::class);
        $before = $groups->listOpenGroups('timeout.summary');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $opened = array_values(array_filter($groups->listOpenGroups('timeout.summary'), static fn (array $group): bool => ! in_array($group['groupId'], array_column($before, 'groupId'), true)));
        self::assertSame('open', $opened[0]['state']);
        $groupId = $opened[0]['groupId'];
        $ecotone->run('workers', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        $ecotone->run('summaries');

        self::assertNull($groups->releaseNow($groupId));
        self::assertSame([], $handler->gathered);
        self::assertContains($groupId, array_column($groups->listOpenGroups('timeout.summary'), 'groupId'));
        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 20], $handler->gathered[0]->results);
        self::assertSame([2], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(GatheredReleaseReason::MANUAL_RELEASE, $handler->gathered[0]->releaseReason);
        self::assertNotContains($groupId, array_column($groups->listOpenGroups('timeout.summary'), 'groupId'));
    }

    #[DataProvider('implementations')]
    public function test_discard_closes_the_group_without_invoking_its_handler(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $groups = $ecotone->getGateway(GatheredGroups::class);
        $before = $groups->listOpenGroups('timeout.summary');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $opened = array_values(array_filter($groups->listOpenGroups('timeout.summary'), static fn (array $group): bool => ! in_array($group['groupId'], array_column($before, 'groupId'), true)));
        $groupId = $opened[0]['groupId'];
        $ecotone->run('workers', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        $ecotone->run('summaries');

        $groups->discard($groupId);
        self::assertSame([], $handler->gathered);
        self::assertNotContains($groupId, array_column($groups->listOpenGroups('timeout.summary'), 'groupId'));
        $closed = array_values(array_filter($groups->listClosedGroups('timeout.summary'), static fn (array $group): bool => $group['groupId'] === $groupId));
        self::assertSame('discarded', $closed[0]['state']);
        self::assertSame([1], $closed[0]['accountedPartNumbers']);
        self::assertSame([2], $closed[0]['missingPartNumbers']);
        self::assertSame($ecotone->now()->unixTime()->inMilliseconds(), $closed[0]['closedAt']);
        $ecotone->advanceTimeBy(new TimeSpan(days: 1));
        $groups->discard($groupId);
        $groups->releaseNow($groupId);
        $ecotone->run('workers');
        $ecotone->run('summaries');
        $after = array_values(array_filter($groups->listClosedGroups('timeout.summary'), static fn (array $group): bool => $group['groupId'] === $groupId));

        self::assertSame([], $handler->gathered);
        self::assertSame($closed[0]['closedAt'], $after[0]['closedAt']);
        self::assertSame(1, $after[0]['latePartCount']);
    }

    #[DataProvider('implementations')]
    public function test_console_lists_open_group_progress(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $groups = $ecotone->getGateway(GatheredGroups::class);
        $before = $groups->listOpenGroups('timeout.summary');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $opened = array_values(array_filter($groups->listOpenGroups('timeout.summary'), static fn (array $group): bool => ! in_array($group['groupId'], array_column($before, 'groupId'), true)));
        $groupId = $opened[0]['groupId'];
        $ecotone->run('workers', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        $ecotone->run('summaries');

        $listing = $ecotone->getServiceFromContainer(ConfiguredMessagingSystem::class)->runConsoleCommand('ecotone:gathering:list', ['gatherChannelName' => 'timeout.summary']);
        $row = array_values(array_filter($listing->rows, static fn (array $row): bool => $row[0] === $groupId));

        self::assertSame(['Group id', 'State', 'Size', 'Accounted parts', 'Missing parts', 'Deadline', 'Late parts'], $listing->columnHeaders);
        self::assertSame([[$groupId, 'open', 2, '1', '2', '', 0]], $row);
    }

    #[DataProvider('implementations')]
    public function test_console_release_reports_requested_before_handler_consumption(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $groups = $ecotone->getGateway(GatheredGroups::class);
        $before = $groups->listOpenGroups('timeout.summary');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $opened = array_values(array_filter($groups->listOpenGroups('timeout.summary'), static fn (array $group): bool => ! in_array($group['groupId'], array_column($before, 'groupId'), true)));
        $groupId = $opened[0]['groupId'];
        $ecotone->run('workers', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        $ecotone->run('summaries');

        $result = $ecotone->getServiceFromContainer(ConfiguredMessagingSystem::class)->runConsoleCommand('ecotone:gathering:release', ['groupId' => $groupId]);
        self::assertSame(['Group id', 'Status'], $result->columnHeaders);
        self::assertSame([[$groupId, 'release requested']], $result->rows);
        self::assertSame([], $handler->gathered);
        self::assertContains($groupId, array_column($groups->listOpenGroups('timeout.summary'), 'groupId'));
        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 20], $handler->gathered[0]->results);
        self::assertSame([2], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(GatheredReleaseReason::MANUAL_RELEASE, $handler->gathered[0]->releaseReason);
        self::assertNotContains($groupId, array_column($groups->listOpenGroups('timeout.summary'), 'groupId'));
    }

    #[DataProvider('implementations')]
    public function test_console_discards_a_group_without_invoking_its_handler(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $groups = $ecotone->getGateway(GatheredGroups::class);
        $before = $groups->listOpenGroups('timeout.summary');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $opened = array_values(array_filter($groups->listOpenGroups('timeout.summary'), static fn (array $group): bool => ! in_array($group['groupId'], array_column($before, 'groupId'), true)));
        $groupId = $opened[0]['groupId'];
        $ecotone->run('workers', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        $ecotone->run('summaries');

        $result = $ecotone->getServiceFromContainer(ConfiguredMessagingSystem::class)->runConsoleCommand('ecotone:gathering:discard', ['groupId' => $groupId]);
        self::assertSame([[$groupId, 'discarded']], $result->rows);
        self::assertSame([], $handler->gathered);
        self::assertNotContains($groupId, array_column($groups->listOpenGroups('timeout.summary'), 'groupId'));
        $closed = array_values(array_filter($groups->listClosedGroups('timeout.summary'), static fn (array $group): bool => $group['groupId'] === $groupId));
        self::assertSame('discarded', $closed[0]['state']);
        self::assertSame([1], $closed[0]['accountedPartNumbers']);
        self::assertSame([2], $closed[0]['missingPartNumbers']);
        self::assertSame($ecotone->now()->unixTime()->inMilliseconds(), $closed[0]['closedAt']);
        $ecotone->advanceTimeBy(new TimeSpan(days: 1));
        $groups->discard($groupId);
        $groups->releaseNow($groupId);
        $ecotone->run('workers');
        $ecotone->run('summaries');
        $after = array_values(array_filter($groups->listClosedGroups('timeout.summary'), static fn (array $group): bool => $group['groupId'] === $groupId));

        self::assertSame([], $handler->gathered);
        self::assertSame($closed[0]['closedAt'], $after[0]['closedAt']);
        self::assertSame(1, $after[0]['latePartCount']);
    }

    #[DataProvider('releaseTriggers')]
    public function test_failed_release_handler_keeps_group_open_until_normal_retry(bool $durable, string $trigger): void
    {
        $handler = new class () {
            public array $gathered = [];
            public bool $fail = true;

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'timeout.split', outputChannelName: 'work', gatherChannelName: 'timeout.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'timeout.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered(timeout: new TimeSpan(minutes: 30))]
            #[Asynchronous('summaries')]
            #[InternalHandler('timeout.summary', endpointId: 'timeout.summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                if ($this->fail) {
                    throw new RuntimeException('Summary failed');
                }
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries', isAutoAcked: false),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $groups = $ecotone->getGateway(GatheredGroups::class);
        $before = $groups->listOpenGroups('timeout.summary');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $opened = array_values(array_filter($groups->listOpenGroups('timeout.summary'), static fn (array $group): bool => ! in_array($group['groupId'], array_column($before, 'groupId'), true)));
        $groupId = $opened[0]['groupId'];
        $ecotone->run('workers', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        $ecotone->run('summaries');
        if ($trigger === 'timeout') {
            $ecotone->advanceTimeBy(new TimeSpan(minutes: 30));
        } else {
            $groups->releaseNow($groupId);
        }
        try {
            $ecotone->run('summaries', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
            self::fail();
        } catch (RuntimeException $failure) {
            self::assertSame('Summary failed', $failure->getMessage());
        }
        $stillOpen = array_values(array_filter($groups->listOpenGroups('timeout.summary'), static fn (array $group): bool => $group['groupId'] === $groupId));
        self::assertCount(1, $stillOpen);
        self::assertSame([1], $stillOpen[0]['accountedPartNumbers']);
        self::assertSame([2], $stillOpen[0]['missingPartNumbers']);
        self::assertSame([], $handler->gathered);
        $handler->fail = false;
        $ecotone->run('summaries', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 20], $handler->gathered[0]->results);
        self::assertSame([2], $handler->gathered[0]->missingPartNumbers);
        self::assertSame($trigger === 'timeout' ? GatheredReleaseReason::TIMEOUT : GatheredReleaseReason::MANUAL_RELEASE, $handler->gathered[0]->releaseReason);
    }

    public static function releaseTriggers(): iterable
    {
        foreach ([false, true] as $durable) {
            foreach (['timeout', 'manual'] as $trigger) {
                yield ($durable ? 'dbal' : 'in-memory') . ': ' . $trigger => [$durable, $trigger];
            }
        }
    }

    #[DataProvider('implementations')]
    public function test_gathered_parts_restore_typed_results_and_original_payload(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('typed.split', outputChannelName: 'typed.parts')]
            public function start(GatheringImport $command): GatheringImport
            {
                return $command;
            }

            #[Splitter('typed.parts', endpointId: 'typed.splitter', outputChannelName: 'typed.work', gatherChannelName: 'typed.summary')]
            public function split(GatheringImport $command): array
            {
                return $command->parts;
            }

            #[InternalHandler('typed.work')]
            public function work(int $part): GatheringPartResult
            {
                return new GatheringPartResult($part * 2);
            }

            #[Gathered]
            #[Asynchronous('typed.summaries')]
            #[InternalHandler('typed.summary', endpointId: 'typed.summary.handler')]
            public function summary(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('typed.summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $command = new GatheringImport('import-1', [10, 20]);
        $ecotone->sendCommandWithRouting('typed.split', $command);
        $ecotone->run('typed.summaries');

        self::assertCount(1, $handler->gathered);
        self::assertEquals($command, $handler->gathered[0]->originalPayload);
        self::assertEquals([1 => new GatheringPartResult(20), 2 => new GatheringPartResult(40)], $handler->gathered[0]->results);
    }

    #[DataProvider('implementations')]
    public function test_original_payload_is_a_snapshot_of_the_message_at_the_split(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('typed.split', outputChannelName: 'typed.parts')]
            public function start(GatheringImport $command): GatheringImport
            {
                return $command;
            }

            #[Splitter('typed.parts', endpointId: 'typed.splitter', outputChannelName: 'typed.work', gatherChannelName: 'typed.summary')]
            public function split(GatheringImport $command): array
            {
                return $command->parts;
            }

            #[InternalHandler('typed.work')]
            public function work(int $part): GatheringPartResult
            {
                return new GatheringPartResult($part * 2);
            }

            #[Gathered]
            #[Asynchronous('typed.summaries')]
            #[InternalHandler('typed.summary', endpointId: 'typed.summary.handler')]
            public function summary(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('typed.summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $command = new GatheringImport('import-1', [10, 20]);
        $ecotone->sendCommandWithRouting('typed.split', $command);
        $command->parts = [99];
        $ecotone->run('typed.summaries');

        self::assertCount(1, $handler->gathered);
        self::assertEquals(new GatheringImport('import-1', [10, 20]), $handler->gathered[0]->originalPayload);
        self::assertEquals([1 => new GatheringPartResult(20), 2 => new GatheringPartResult(40)], $handler->gathered[0]->results);
    }

    #[DataProvider('implementations')]
    public function test_progress_lists_accounted_parts_in_split_order(bool $durable): void
    {
        $handler = new class () {
            #[CommandHandler('progress.split', outputChannelName: 'progress.parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('progress.parts', endpointId: 'progress.splitter', outputChannelName: 'progress.work', gatherChannelName: 'progress.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Router('progress.work')]
            public function route(int $part): string
            {
                return match ($part) {
                    1 => 'progress.slow',
                    2 => 'progress.medium',
                    default => 'progress.fast',
                };
            }

            #[Asynchronous('slow_workers')]
            #[InternalHandler('progress.slow', endpointId: 'progress.slow.handler')]
            public function slow(int $part): int
            {
                return $part;
            }

            #[Asynchronous('medium_workers')]
            #[InternalHandler('progress.medium', endpointId: 'progress.medium.handler')]
            public function medium(int $part): int
            {
                return $part;
            }

            #[InternalHandler('progress.fast')]
            public function fast(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('progress_summaries')]
            #[InternalHandler('progress.summary', endpointId: 'progress.summary.handler')]
            public function summary(GatheredParts $parts): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $groups = $ecotone->getGateway(GatheredGroups::class);
        $before = $groups->listOpenGroups('progress.summary');
        $ecotone->sendCommandWithRouting('progress.split', [1, 2, 3]);
        $ecotone->run('progress_summaries');
        $ecotone->run('medium_workers');
        $ecotone->run('progress_summaries');
        $opened = array_values(array_filter($groups->listOpenGroups('progress.summary'), static fn (array $group): bool => ! in_array($group['groupId'], array_column($before, 'groupId'), true)));

        self::assertCount(1, $opened);
        self::assertSame([2, 3], $opened[0]['accountedPartNumbers']);
        self::assertSame([1], $opened[0]['missingPartNumbers']);
        $groups->releaseNow($opened[0]['groupId']);
        $ecotone->run('progress_summaries');
        $closed = array_values(array_filter($groups->listClosedGroups('progress.summary'), static fn (array $group): bool => $group['groupId'] === $opened[0]['groupId']));
        self::assertSame([2, 3], $closed[0]['accountedPartNumbers']);
    }

    public static function implementations(): iterable
    {
        yield 'in-memory' => [false];
        yield 'dbal' => [true];
    }

    #[DataProvider('implementations')]
    public function test_split_opens_a_group_until_all_parts_are_accounted_for(bool $durable): void
    {
        $handler = new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'work.handler')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $previousGroups = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary');
        $ecotone->sendCommandWithRouting('split', [10, 20]);

        $groups = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary');
        self::assertCount(count($previousGroups) + 1, $groups);
        $groups = array_values(array_filter($groups, static fn (array $group): bool => ! in_array($group['groupId'], array_column($previousGroups, 'groupId'), true)));
        self::assertSame(2, $groups[0]['size']);
        self::assertSame([], $groups[0]['accountedPartNumbers']);
        self::assertSame([1, 2], $groups[0]['missingPartNumbers']);
    }

    #[DataProvider('implementations')]
    public function test_open_group_lists_accounted_and_missing_parts(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $previousGroupIds = array_column($ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary'), 'groupId');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        self::assertSame([], $handler->gathered);

        $ecotone->run('summaries', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        $groups = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary');
        $groups = array_values(array_filter($groups, static fn (array $group): bool => ! in_array($group['groupId'], $previousGroupIds, true)));
        self::assertCount(1, $groups);
        self::assertSame([1], $groups[0]['accountedPartNumbers']);
        self::assertSame([2], $groups[0]['missingPartNumbers']);
    }

    public function test_gathered_handler_database_failure_is_not_rewritten_as_missing_gathering_tables(): void
    {
        $handler = new class () {
            public array $gathered = [];
            public bool $shouldFail = true;

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                if ($this->shouldFail) {
                    (new DbalConnectionFactory(getenv('DATABASE_DSN')))->createContext()->getDbalConnection()->executeQuery('SELECT * FROM application_missing_summary_table');
                }
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries', isAutoAcked: false)],
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $previousGroupIds = array_column($ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary'), 'groupId');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->run('summaries', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        try {
            $ecotone->run('summaries', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
            self::fail();
        } catch (Throwable $failure) {
            self::assertStringContainsString('application_missing_summary_table', $failure->getMessage());
        }
        $groups = array_values(array_filter($ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary'), static fn (array $group): bool => ! in_array($group['groupId'], $previousGroupIds, true)));
        self::assertCount(1, $groups);
        self::assertSame([1], $groups[0]['accountedPartNumbers']);
        self::assertSame([2], $groups[0]['missingPartNumbers']);
        self::assertSame([], $handler->gathered);

        $handler->shouldFail = false;
        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 10, 2 => 20], $handler->gathered[0]->results);
    }

    #[DataProvider('implementations')]
    public function test_gathered_handler_failure_rolls_back_the_completing_part(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];
            public bool $shouldFail = true;

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                if ($this->shouldFail) {
                    throw new RuntimeException('Gathered handler failed');
                }
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries', isAutoAcked: false)],
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $previousGroupIds = array_column($ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary'), 'groupId');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->run('summaries', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        try {
            $ecotone->run('summaries', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
            self::fail();
        } catch (RuntimeException $failure) {
            self::assertSame('Gathered handler failed', $failure->getMessage());
        }
        $groups = array_values(array_filter($ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary'), static fn (array $group): bool => ! in_array($group['groupId'], $previousGroupIds, true)));
        self::assertCount(1, $groups);
        self::assertSame([1], $groups[0]['accountedPartNumbers']);
        self::assertSame([2], $groups[0]['missingPartNumbers']);
        self::assertSame([], $handler->gathered);

        $handler->shouldFail = false;
        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 10, 2 => 20], $handler->gathered[0]->results);
    }

    #[DataProvider('implementations')]
    public function test_gathered_handler_receives_all_part_results_in_split_order(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        self::assertSame([], $handler->gathered);

        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 10, 2 => 20], $handler->gathered[0]->results);
        self::assertSame([], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(2, $handler->gathered[0]->size);
        self::assertSame([10, 20], $handler->gathered[0]->originalPayload);
    }

    #[DataProvider('implementations')]
    public function test_duplicate_part_is_not_counted_twice(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];
            public int $workCalls = 0;

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'work.handler')]
            public function work(int $part): int
            {
                $this->workCalls++;

                return $part;
            }

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE - 1, pointcut: AsynchronousRunningEndpoint::class)]
            public function duplicateWorkerDelivery(MethodInvocation $invocation, AsynchronousRunningEndpoint $endpoint): mixed
            {
                $again = $invocation->cloneCurrentState();
                $result = $invocation->proceed();
                if ($endpoint->getEndpointId() === 'workers') {
                    $again->proceed();
                }

                return $result;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [...($durable ? [DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false)] : []), SimpleMessageChannelBuilder::createQueueChannel('summaries'), SimpleMessageChannelBuilder::createQueueChannel('workers')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $before = array_column($ecotone->getGateway(GatheredGroups::class)->listClosedGroups('summary'), 'groupId');
        $ecotone->sendCommandWithRouting('split', [10, 20]);
        self::assertSame([], $handler->gathered);

        $ecotone->run('workers')->run('summaries');
        self::assertSame(4, $handler->workCalls);
        $closed = array_values(array_filter($ecotone->getGateway(GatheredGroups::class)->listClosedGroups('summary'), static fn (array $group): bool => ! in_array($group['groupId'], $before, true)));
        self::assertSame(0, $closed[0]['latePartCount']);

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 10, 2 => 20], $handler->gathered[0]->results);
        self::assertSame([], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(2, $handler->gathered[0]->size);
        self::assertSame([10, 20], $handler->gathered[0]->originalPayload);
    }

    #[DataProvider('implementations')]
    public function test_part_returning_nothing_counts_as_done(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): void
            {
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        self::assertSame([], $handler->gathered);

        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => null, 2 => null], $handler->gathered[0]->results);
        self::assertSame([], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(2, $handler->gathered[0]->size);
        self::assertSame([10, 20], $handler->gathered[0]->originalPayload);
    }

    #[DataProvider('implementations')]
    public function test_splitter_endpoint_ids_can_contain_another_endpoint_id_followed_by_core(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'outer.parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('outer.parts', endpointId: 'outer.split.endpoint', outputChannelName: 'inner.parts', gatherChannelName: 'outer.summary')]
            public function outer(array $parts): array
            {
                return $parts;
            }

            #[Splitter('inner.parts', endpointId: 'outer.split.endpoint.core', outputChannelName: 'work', gatherChannelName: 'inner.summary')]
            public function inner(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('inner.summary', endpointId: 'inner.summary.handler')]
            public function summarizeInner(GatheredParts $parts): int
            {
                return array_sum($parts->results);
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('outer.summary', endpointId: 'outer.summary.handler')]
            public function summarizeOuter(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [[10, 20], [30]])->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 30, 2 => 30], $handler->gathered[0]->results);
        self::assertSame([[10, 20], [30]], $handler->gathered[0]->originalPayload);
    }

    #[DataProvider('implementations')]
    public function test_inner_gathered_result_becomes_outer_part_result(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'outer.parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('outer.parts', endpointId: 'outer.split.endpoint', outputChannelName: 'inner.parts', gatherChannelName: 'outer.summary')]
            public function outer(array $parts): array
            {
                return $parts;
            }

            #[Splitter('inner.parts', endpointId: 'inner.split.endpoint', outputChannelName: 'work', gatherChannelName: 'inner.summary')]
            public function inner(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('inner.summary', endpointId: 'inner.summary.handler')]
            public function summarizeInner(GatheredParts $parts): int
            {
                return array_sum($parts->results);
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('outer.summary', endpointId: 'outer.summary.handler')]
            public function summarizeOuter(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [[10, 20], [30]])->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 30, 2 => 30], $handler->gathered[0]->results);
        self::assertSame([[10, 20], [30]], $handler->gathered[0]->originalPayload);
    }

    #[DataProvider('implementations')]
    public function test_redelivered_empty_split_trigger_is_not_counted_as_a_late_part(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE - 1, pointcut: AsynchronousRunningEndpoint::class)]
            public function duplicateWorkerDelivery(MethodInvocation $invocation, AsynchronousRunningEndpoint $endpoint): mixed
            {
                $again = $invocation->cloneCurrentState();
                $result = $invocation->proceed();
                if ($endpoint->getEndpointId() === 'summaries') {
                    $again->proceed();
                }

                return $result;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [...($durable ? [DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: false)] : []), SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $before = array_column($ecotone->getGateway(GatheredGroups::class)->listClosedGroups('summary'), 'groupId');
        $ecotone->sendCommandWithRouting('split', []);
        self::assertSame([], $handler->gathered);

        $ecotone->run('summaries');

        $closed = array_values(array_filter($ecotone->getGateway(GatheredGroups::class)->listClosedGroups('summary'), static fn (array $group): bool => ! in_array($group['groupId'], $before, true)));
        self::assertSame(0, $closed[0]['latePartCount']);
        self::assertCount(1, $handler->gathered);
        self::assertSame([], $handler->gathered[0]->results);
        self::assertSame([], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(0, $handler->gathered[0]->size);
        self::assertSame([], $handler->gathered[0]->originalPayload);
    }

    #[DataProvider('implementations')]
    public function test_direct_gathered_command_names_the_supported_ways_out(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[CommandHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('summary', new GatheredParts(
            results: [],
            missingPartNumbers: [],
            size: 0,
            originalPayload: [],
            releaseReason: GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR,
        ));
        try {
            $ecotone->run('summaries');
            self::fail();
        } catch (\Throwable $failure) {
            self::assertStringContainsString('Send a message to the splitter', $failure->getMessage());
            self::assertStringContainsString('remove #[Gathered]', $failure->getMessage());
        }
    }

    #[DataProvider('implementations')]
    public function test_empty_split_trigger_does_not_inherit_the_split_messages_delivery_delay(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [], metadata: [MessageHeaders::DELIVERY_DELAY => 60_000]);
        self::assertSame([], $handler->gathered);

        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([], $handler->gathered[0]->results);
        self::assertSame([], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(0, $handler->gathered[0]->size);
        self::assertSame([], $handler->gathered[0]->originalPayload);
    }

    public function test_missing_gathering_tables_name_setup_and_migration_instructions(): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory('sqlite:///:memory:')],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        try {
            $ecotone->sendCommandWithRouting('split', []);
            self::fail();
        } catch (\Throwable $failure) {
            self::assertStringContainsString("'gathering' feature", $failure->getMessage());
            self::assertStringContainsString("initialize('gathering')", $failure->getMessage());
            self::assertStringContainsString("getCreateSqlStatementsForFeatures(['gathering'])", $failure->getMessage());
            self::assertStringContainsString('Ecotone never creates a table while it handles a message', $failure->getMessage());
        }
    }

    #[DataProvider('implementations')]
    public function test_empty_split_runs_gathered_handler_with_zero_parts(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', []);
        self::assertSame([], $handler->gathered);

        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([], $handler->gathered[0]->results);
        self::assertSame([], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(0, $handler->gathered[0]->size);
        self::assertSame([], $handler->gathered[0]->originalPayload);
    }

    #[DataProvider('implementations')]
    public function test_released_message_carries_application_headers_but_not_the_split_requests_routing(bool $durable): void
    {
        $handler = new class () {
            public array $worked = [];
            public array $summarized = [];

            #[CommandHandler('split', outputChannelName: 'flow')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Orchestrator('flow')]
            public function flow(): array
            {
                return ['parts', 'work'];
            }

            #[Splitter('parts', endpointId: 'split.parts', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                $this->worked[] = $part;

                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts, #[Header('importId')] string $importId): int
            {
                $this->summarized[] = $importId;

                return 100;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [], metadata: ['importId' => 'import-1'])->run('summaries');

        self::assertSame(['import-1'], $handler->summarized);
        self::assertSame([], $handler->worked);
    }

    #[DataProvider('implementations')]
    public function test_group_headers_do_not_cross_a_command_bus_hop(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[PropagateHeaders]
            #[InternalHandler('work')]
            public function work(int $part, #[Reference] CommandBus $commands): int
            {
                $commands->sendWithRouting('audit', $part);

                return $part;
            }

            #[CommandHandler('audit')]
            public function audit(int $part): void
            {
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        self::assertSame([], $handler->gathered);

        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 10, 2 => 20], $handler->gathered[0]->results);
        self::assertSame([], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(2, $handler->gathered[0]->size);
        self::assertSame([10, 20], $handler->gathered[0]->originalPayload);
    }

    #[DataProvider('implementations')]
    public function test_group_headers_do_not_cross_an_event_bus_hop(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[PropagateHeaders]
            #[InternalHandler('work')]
            public function work(int $part, #[Reference] EventBus $events): int
            {
                $events->publishWithRouting('audit', $part);

                return $part;
            }

            #[EventHandler('audit')]
            public function audit(int $part): void
            {
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        self::assertSame([], $handler->gathered);

        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 10, 2 => 20], $handler->gathered[0]->results);
        self::assertSame([], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(2, $handler->gathered[0]->size);
        self::assertSame([10, 20], $handler->gathered[0]->originalPayload);
    }

    #[DataProvider('implementations')]
    public function test_gathered_command_handler_receives_all_part_results(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[CommandHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        self::assertSame([], $handler->gathered);

        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 10, 2 => 20], $handler->gathered[0]->results);
        self::assertSame([], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(2, $handler->gathered[0]->size);
        self::assertSame([10, 20], $handler->gathered[0]->originalPayload);
    }

    #[DataProvider('implementations')]
    public function test_gathered_parts_report_all_parts_accounted_for_release_reason(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        self::assertSame([], $handler->gathered);

        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame('all_parts_accounted_for', $handler->gathered[0]->releaseReason->value ?? null);
        self::assertInstanceOf(GatheredReleaseReason::class, $handler->gathered[0]->releaseReason);
        self::assertSame([], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(2, $handler->gathered[0]->size);
        self::assertSame([10, 20], $handler->gathered[0]->originalPayload);
    }

    #[DataProvider('implementations')]
    public function test_optional_router_with_no_match_counts_as_done_without_result(bool $durable): void
    {
        $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Router('work', isResolutionRequired: false)]
            public function route(int $part): array
            {
                return [];
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        self::assertSame([], $handler->gathered);

        $ecotone->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => null, 2 => null], $handler->gathered[0]->results);
        self::assertSame([], $handler->gathered[0]->missingPartNumbers);
        self::assertSame(2, $handler->gathered[0]->size);
        self::assertSame([10, 20], $handler->gathered[0]->originalPayload);
    }

    public function test_gathered_handler_runs_once_when_last_two_parts_are_recorded_concurrently(): void
    {
        if ((new DbalConnectionFactory(getenv('DATABASE_DSN')))->createContext()->getDbalConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database-wide lock, so this two-connection race is proved on PostgreSQL, MySQL and MariaDB only.');
        }

        self::concurrentApplication()->run('gathering_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $testId = bin2hex(random_bytes(16));
        self::concurrentApplication()->sendCommandWithRouting('split', [10, 20], metadata: ['testId' => $testId]);
        $this->runConcurrentConsumers(2);
        self::concurrentApplication()->run('gathering_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        self::assertJsonStringEqualsJsonString('{"results":{"1":10,"2":20},"runs":1}', self::concurrentApplication()->getGateway(DocumentStore::class)->getDocument('gathering_concurrency', $testId));
    }

    public function test_concurrent_part_reports_conflict_and_are_retried_without_reaching_the_dead_letter(): void
    {
        if ((new DbalConnectionFactory(getenv('DATABASE_DSN')))->createContext()->getDbalConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database-wide lock, so this two-connection race is proved on PostgreSQL, MySQL and MariaDB only.');
        }

        $application = self::concurrentApplication();
        $application->run('gathering_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        $deadLetters = $application->getGateway(DeadLetterGateway::class);
        $previousFailures = array_map(static fn ($failure): string => $failure->getMessageId(), $deadLetters->list(1000, 0));
        $testId = bin2hex(random_bytes(16));
        $parts = range(1, 8);
        $previousGroups = array_column($application->getGateway(GatheredGroups::class)->listOpenGroups('summary'), 'groupId');
        $application->sendCommandWithRouting('split', $parts, metadata: ['testId' => $testId]);

        $this->runConcurrentConsumers(count($parts));

        $groups = array_values(array_filter($application->getGateway(GatheredGroups::class)->listOpenGroups('summary'), static fn (array $group): bool => ! in_array($group['groupId'], $previousGroups, true)));
        self::assertCount(1, $groups);
        self::assertLessThan(count($parts), count($groups[0]['accountedPartNumbers']));
        $originalDeliveries = array_values(array_filter($application->getGateway(DocumentStore::class)->getAllDocuments('gathering_deliveries'), static fn (string $delivery): bool => json_decode($delivery, true, flags: JSON_THROW_ON_ERROR)['testId'] === $testId));
        self::assertCount(count($parts), $originalDeliveries);
        $application->run('gathering_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        $deliveries = array_values(array_filter($application->getGateway(DocumentStore::class)->getAllDocuments('gathering_deliveries'), static fn (string $delivery): bool => json_decode($delivery, true, flags: JSON_THROW_ON_ERROR)['testId'] === $testId));
        self::assertGreaterThan(count($parts), count($deliveries));
        self::assertLessThanOrEqual(count($parts) * count($parts), count($deliveries));
        $result = json_decode($application->getGateway(DocumentStore::class)->getDocument('gathering_concurrency', $testId), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(array_combine($parts, $parts), $result['results']);
        self::assertSame(1, $result['runs']);
        $failures = array_values(array_filter($deadLetters->list(1000, 0), static fn ($failure): bool => ! in_array($failure->getMessageId(), $previousFailures, true)));
        self::assertSame([], $failures);
    }

    private function runConcurrentConsumers(int $numberOfConsumers): void
    {
        $children = [];
        $packageAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $autoload = is_file($packageAutoload) ? $packageAutoload : dirname(__DIR__, 4) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . '; require ' . var_export(__FILE__, true) . '; ' . self::class . '::consumeConcurrentPart();';
        for ($part = 0; $part < $numberOfConsumers; $part++) {
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $children[] = [$process, $pipes];
        }
        try {
            $ready = [];
            foreach ($children as [$process, $pipes]) {
                $ready[] = fgets($pipes[1]);
            }
            foreach ($children as $number => [$process, $pipes]) {
                if ($ready[$number] === "ready\n") {
                    fwrite($pipes[0], "consume\n");
                }
                fclose($pipes[0]);
            }
            self::assertSame(array_fill(0, $numberOfConsumers, "ready\n"), $ready);
            foreach ($children as [$process, $pipes]) {
                self::assertSame('', stream_get_contents($pipes[1]));
                self::assertSame('', stream_get_contents($pipes[2]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process));
            }
        } finally {
            foreach ($children as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }

    }

    public function test_releasing_transaction_sees_a_part_committed_after_its_snapshot_was_established(): void
    {
        if ((new DbalConnectionFactory(getenv('DATABASE_DSN')))->createContext()->getDbalConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database-wide lock, so this two-connection race is proved on PostgreSQL, MySQL and MariaDB only.');
        }

        $other = self::concurrentApplication();
        $other->run('gathering_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $testId = bin2hex(random_bytes(16));
        $other->getGateway(DocumentStore::class)->addDocument('gathering_snapshot', $testId, '{"ready":true}');
        $other->sendCommandWithRouting('split', [10, 20], metadata: ['testId' => $testId]);
        $interceptor = new class ($other, $testId) {
            public bool $interleaved = false;
            public array $messageIds = [];

            public function __construct(private FlowTestSupport $other, private string $testId)
            {
            }

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 101, pointcut: AsynchronousRunningEndpoint::class)]
            public function recordOtherPartAfterSnapshot(MethodInvocation $invocation, Message $message, #[Reference] DocumentStore $documents): mixed
            {
                $this->messageIds[] = $message->getHeaders()->getMessageId();
                if (! $this->interleaved) {
                    $documents->getDocument('gathering_snapshot', $this->testId);
                    $this->other->run('gathering_concurrency', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1)->withExecutionAmountLimit(1));
                    $this->interleaved = true;
                }

                return $invocation->proceed();
            }
        };

        $consumer = self::concurrentApplication([$interceptor]);
        $consumer->run('gathering_concurrency', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1)->withExecutionAmountLimit(1));

        self::assertTrue($interceptor->interleaved);
        if (! str_starts_with(getenv('DATABASE_DSN'), 'pgsql:')) {
            self::assertNull($consumer->getGateway(DocumentStore::class)->findDocument('gathering_concurrency', $testId));
            $consumer->run('gathering_concurrency', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1)->withExecutionAmountLimit(1));
            self::assertCount(2, $interceptor->messageIds);
            self::assertNotSame($interceptor->messageIds[0], $interceptor->messageIds[1]);
        }
        self::assertJsonStringEqualsJsonString('{"results":{"1":10,"2":20},"runs":1}', self::concurrentApplication()->getGateway(DocumentStore::class)->getDocument('gathering_concurrency', $testId));
    }

    #[DataProvider('conflictingReleaseTriggers')]
    public function test_conflicting_release_trigger_is_redelivered_before_releasing_a_fresh_snapshot(string $trigger): void
    {
        if ((new DbalConnectionFactory(getenv('DATABASE_DSN')))->createContext()->getDbalConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database-wide lock, so this two-connection race is proved on PostgreSQL, MySQL and MariaDB only.');
        }

        $channels = [SimpleMessageChannelBuilder::createQueueChannel('gathering_release'), SimpleMessageChannelBuilder::createQueueChannel('gathering_release_workers')];
        $other = self::releaseApplication([], $channels);
        $testId = bin2hex(random_bytes(16));
        $other->getGateway(DocumentStore::class)->addDocument('gathering_snapshot', $testId, '{"ready":true}');
        $previousGroups = array_column($other->getGateway(GatheredGroups::class)->listOpenGroups('summary'), 'groupId');
        $other->sendCommandWithRouting('split', [10, 20], metadata: ['testId' => $testId]);
        if ($trigger === 'manual') {
            $groups = array_values(array_filter($other->getGateway(GatheredGroups::class)->listOpenGroups('summary'), static fn (array $group): bool => ! in_array($group['groupId'], $previousGroups, true)));
            $other->getGateway(GatheredGroups::class)->releaseNow($groups[0]['groupId']);
        }
        $interceptor = new class ($other, $testId) {
            public bool $interleaved = false;
            public array $messageIds = [];

            public function __construct(private FlowTestSupport $other, private string $testId)
            {
            }

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 101, pointcut: AsynchronousRunningEndpoint::class)]
            public function recordPartAfterSnapshot(MethodInvocation $invocation, Message $message, #[Reference] DocumentStore $documents): mixed
            {
                $this->messageIds[] = $message->getHeaders()->getMessageId();
                if (! $this->interleaved) {
                    $documents->getDocument('gathering_snapshot', $this->testId);
                    $this->other->run('gathering_release_workers', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1)->withExecutionAmountLimit(1));
                    $this->other->run('gathering_release', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1)->withExecutionAmountLimit(1));
                    $this->interleaved = true;
                }

                return $invocation->proceed();
            }
        };
        $consumer = self::releaseApplication([$interceptor], $channels);
        if ($trigger === 'timeout') {
            $consumer->advanceTimeBy(new TimeSpan(seconds: 30));
        }
        $consumer->run('gathering_release', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1)->withExecutionAmountLimit(1));

        self::assertTrue($interceptor->interleaved);
        if (! str_starts_with(getenv('DATABASE_DSN'), 'pgsql:')) {
            self::assertNull($consumer->getGateway(DocumentStore::class)->findDocument('gathering_release', $testId));
            $consumer->run('gathering_release', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1)->withExecutionAmountLimit(1));
            self::assertCount(2, $interceptor->messageIds);
            self::assertNotSame($interceptor->messageIds[0], $interceptor->messageIds[1]);
        }
        $result = json_decode($consumer->getGateway(DocumentStore::class)->getDocument('gathering_release', $testId), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([1 => 10], $result['results']);
        self::assertSame([2], $result['missing']);
        self::assertSame(($trigger === 'manual' ? GatheredReleaseReason::MANUAL_RELEASE : GatheredReleaseReason::TIMEOUT)->value, $result['reason']);
    }

    public static function conflictingReleaseTriggers(): iterable
    {
        yield 'manual' => ['manual'];
        yield 'timeout' => ['timeout'];
    }

    public static function consumeConcurrentPart(): void
    {
        $barrier = new class () {
            private bool $released = false;

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 101, pointcut: AsynchronousRunningEndpoint::class)]
            public function awaitOtherConsumer(MethodInvocation $invocation): mixed
            {
                if (! $this->released) {
                    fwrite(STDOUT, "ready\n");
                    if (fgets(STDIN) !== "consume\n") {
                        throw new RuntimeException('Concurrent consumer start was not released.');
                    }
                    $this->released = true;
                }

                return $invocation->proceed();
            }
        };
        self::concurrentApplication([$barrier])->run('gathering_concurrency', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false)->withExecutionAmountLimit(1));
    }

    private static function concurrentApplication(array $additionalServices = []): FlowTestSupport
    {
        $handler = new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('gathering_concurrency')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts, #[Header('testId')] string $testId, #[Reference] DocumentStore $documents): void
            {
                $previous = $documents->findDocument('gathering_concurrency', $testId);
                $runs = $previous === null ? 1 : json_decode($previous, true, flags: JSON_THROW_ON_ERROR)['runs'] + 1;
                $documents->upsertDocument('gathering_concurrency', $testId, json_encode(['results' => $parts->results, 'runs' => $runs], JSON_THROW_ON_ERROR), DocumentStore::LAST_WRITE_WINS);
            }
        };
        $deliveries = new class () {
            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 102, pointcut: AsynchronousRunningEndpoint::class)]
            public function recordDelivery(MethodInvocation $invocation, Message $message, #[Reference] DocumentStore $documents): mixed
            {
                $result = $invocation->proceed();
                $documents->addDocument('gathering_deliveries', $message->getHeaders()->getMessageId(), json_encode(['testId' => $message->getHeaders()->get('testId')], JSON_THROW_ON_ERROR));

                return $result;
            }
        };
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, $deliveries::class, ...array_map(static fn (object $service): string => $service::class, $additionalServices)],
            containerOrAvailableServices: [$handler, $deliveries, ...$additionalServices, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: [DbalBackedMessageChannelBuilder::create('gathering_concurrency'), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter')],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
    }

    private static function releaseApplication(array $additionalServices, array $channels): FlowTestSupport
    {
        $handler = new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('gathering_release_workers')]
            #[InternalHandler('work', endpointId: 'release.work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered(timeout: new TimeSpan(seconds: 30))]
            #[Asynchronous('gathering_release')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts, #[Header('testId')] string $testId, #[Reference] DocumentStore $documents): void
            {
                $documents->addDocument('gathering_release', $testId, json_encode(['results' => $parts->results, 'missing' => $parts->missingPartNumbers, 'reason' => $parts->releaseReason->value], JSON_THROW_ON_ERROR));
            }
        };
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, ...array_map(static fn (object $service): string => $service::class, $additionalServices)],
            containerOrAvailableServices: [$handler, ...$additionalServices, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: $channels,
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
    }
}

final class GatheringImport
{
    public function __construct(public readonly string $importId, public array $parts)
    {
    }
}

final class GatheringPartResult
{
    public function __construct(public readonly int $value)
    {
    }
}
