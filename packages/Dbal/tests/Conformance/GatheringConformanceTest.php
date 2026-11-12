<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

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
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
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
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Messaging\GatheredReleaseReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
        );


        $groups = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary');
        self::assertCount(count($previousGroups) + 1, $groups);
        $groups = array_values(array_filter($groups, static fn (array $group): bool => ! in_array($group['groupId'], array_column($previousGroups, 'groupId'), true)));
        self::assertSame(2, $groups[0]['size']);
        self::assertSame([], $groups[0]['accountedPartNumbers']);
        self::assertSame([1, 2], $groups[0]['missingPartNumbers']);
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
        );

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        self::assertSame([], $handler->gathered);

        $ecotone->run('workers')->run('summaries');
        self::assertSame(4, $handler->workCalls);

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
        );

        $ecotone->sendCommandWithRouting('split', [[10, 20], [30]])->run('summaries');

        self::assertCount(1, $handler->gathered);
        self::assertSame([1 => 30, 2 => 30], $handler->gathered[0]->results);
        self::assertSame([[10, 20], [30]], $handler->gathered[0]->originalPayload);
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
        self::concurrentApplication()->run('gathering_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $testId = bin2hex(random_bytes(16));
        self::concurrentApplication()->sendCommandWithRouting('split', [10, 20], metadata: ['testId' => $testId]);
        $children = [];
        $code = 'require ' . var_export(dirname(__DIR__, 4) . '/vendor/autoload.php', true) . '; require ' . var_export(__FILE__, true) . '; ' . self::class . '::consumeConcurrentPart();';
        for ($part = 0; $part < 2; $part++) {
            $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $children[] = [$process, $pipes];
        }
        foreach ($children as [$process, $pipes]) {
            self::assertSame('', stream_get_contents($pipes[1]));
            self::assertSame('', stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
        }

        self::assertJsonStringEqualsJsonString('{"results":{"1":10,"2":20}}', self::concurrentApplication()->getGateway(DocumentStore::class)->getDocument('gathering_concurrency', $testId));
    }

    public function test_releasing_transaction_sees_a_part_committed_after_its_snapshot_was_established(): void
    {
        $other = self::concurrentApplication();
        $other->run('gathering_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $testId = bin2hex(random_bytes(16));
        $other->getGateway(DocumentStore::class)->addDocument('gathering_snapshot', $testId, '{"ready":true}');
        $other->sendCommandWithRouting('split', [10, 20], metadata: ['testId' => $testId]);
        $interceptor = new class ($other, $testId) {
            public bool $interleaved = false;

            public function __construct(private FlowTestSupport $other, private string $testId)
            {
            }

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 101, pointcut: AsynchronousRunningEndpoint::class)]
            public function recordOtherPartAfterSnapshot(MethodInvocation $invocation, #[Reference] DocumentStore $documents): mixed
            {
                $documents->getDocument('gathering_snapshot', $this->testId);
                $this->other->run('gathering_concurrency', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
                $this->interleaved = true;

                return $invocation->proceed();
            }
        };

        self::concurrentApplication([$interceptor])->run('gathering_concurrency', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));

        self::assertTrue($interceptor->interleaved);
        self::assertJsonStringEqualsJsonString('{"results":{"1":10,"2":20}}', self::concurrentApplication()->getGateway(DocumentStore::class)->getDocument('gathering_concurrency', $testId));
    }

    public static function consumeConcurrentPart(): void
    {
        self::concurrentApplication()->run('gathering_concurrency', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
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
                $documents->addDocument('gathering_concurrency', $testId, json_encode(['results' => $parts->results], JSON_THROW_ON_ERROR));
            }
        };
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, ...array_map(static fn (object $service): string => $service::class, $additionalServices)],
            containerOrAvailableServices: [$handler, ...$additionalServices, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: [DbalBackedMessageChannelBuilder::create('gathering_concurrency')],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
        );
    }
}
