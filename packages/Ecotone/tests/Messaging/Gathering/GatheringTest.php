<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Gathering;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Orchestrator;
use Ecotone\Api\Attribute\PropagateHeaders;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Messaging\GatheredReleaseReason;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class GatheringTest extends TestCase
{
    private const ENTERPRISE_LICENCE = 'eyJzaWduYXR1cmUiOiJxRmtrSDdCQkxzdUlTRzExZmVXeHVKVTY3bGZScmFSTFBpb0VUaGdoZTAyYWRjajlVRkNWWXhvaGl4YXhoaEFlNzU5aExOT0R5NXo1T2N2eFppUGQyemRRblYrb1dNZjFwM2lqbFN4cUg1MDhVV1wvOG90TllkM28wMVRZRmdMSjU4RXJzYThLcUJib0Z1MmhaZW9lczQ3elM2VHR5TzFGV2pOeWxlbG1aRWNLUHFydFhzOTNcL1YxY0wwWVg4UFBOZDl6bVRPOWxyc3UzM3F1anBXVlB4eVRMTW4yK3lPc3Jxa2pJZDArSU1LSWl0dG1IZGlBcml6NnZIXC93RnFKTU5Od2JKWnFsUXRNa3pQc3pvRlFseVgyZnNPTG5CTWNCajA5S0VnNjhVcG14OVwvUnBHZXBNV2UzcThYeFZMa3laOEtXdndEN2JBZDZPSXVoejlxRThyamVQVVVsbUxybm0wd3ZFYjV6UVZEbU5OY21uZnNkMEYyazJhSldySzc5VXlEWVY3UFNvVkZiM0diV0VNN2p1STVFV2w5cTVaWXJiOGxFcmxTTmNpYmQwbCttMWkzUWk1TnZhU1RscHBTV1BGS0g4c2x5dDhKRzV4YXZqQlJFU3BJWGVDNE9VREdoZDRJNEhaaEpDXC9Wcm9Nb0FRNzBMaXRrUHcxWkdWQ1dqTURucHdNdWRyWThpMVQwak5MRytXYlpYckpvRHZxd0x4SENKdU9EWnhuYVNISkVVSVFndGViUXZPRURIKzBjN0o0VVJMWkV2NnU2d2tcL1F6TzlPVnVVS1BubDkzK1dVam8xVDZqOEJ0MEtSeHhsMmtBY2pQcE9wWTg5Q25BQlBtckF4THJhYkxXeThpZmxVQ0cwUjNmZzB4UnpiMjlMSENvUW95b2NmRStzYjhFYz0iLCJkYXRhIjp7ImVtYWlsIjoiam9obnlAd3AucGwiLCJleHBpcmVBdCI6IjIwMzItMTAtMTEgMDA6MDA6MDAiLCJpc0VudGVycHJpc2VQbHVzIjpmYWxzZX19';

    public function test_gathered_parts_report_all_parts_accounted_for_release_reason(): void
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
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: [],
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
    public function test_splitter_with_gather_channel_requires_enterprise_licence_at_bootstrap(): void
    {
        $handler = new class () {
            #[Splitter('parts', outputChannelName: 'work', gatherChannelName: 'summary')]
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
            }
        };

        $this->expectExceptionMessage("Splitter gather channel 'summary' requires an Ecotone Enterprise licence. Provide a licence key through EcotoneLite::bootstrapFlowTesting(licenceKey: ...), or remove gatherChannelName from #[Splitter].");

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: [],
        );
    }

    public function test_split_opens_a_group_until_all_parts_are_accounted_for(): void
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
            containerOrAvailableServices: [$handler],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
            ],
            enabledModules: [],
            licenceKey: self::ENTERPRISE_LICENCE,
        );

        $ecotone->sendCommandWithRouting('split', [10, 20]);

        $groups = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary');
        self::assertCount(1, $groups);
        self::assertSame(2, $groups[0]['size']);
        self::assertSame([], $groups[0]['accountedPartNumbers']);
        self::assertSame([1, 2], $groups[0]['missingPartNumbers']);
    }

    public function test_part_returning_nothing_counts_as_done(): void
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
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: [],
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
    public function test_group_headers_do_not_cross_a_command_bus_hop(): void
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
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: [],
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
    public function test_gathered_handler_receives_all_part_results_in_split_order(): void
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
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: [],
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
    public function test_released_message_carries_application_headers_but_not_the_split_requests_routing(): void
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
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: [],
            licenceKey: self::ENTERPRISE_LICENCE,
        );

        $ecotone->sendCommandWithRouting('split', [], metadata: ['importId' => 'import-1'])->run('summaries');

        self::assertSame(['import-1'], $handler->summarized);
        self::assertSame([], $handler->worked);
    }

    public function test_empty_split_runs_gathered_handler_with_zero_parts(): void
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
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('summaries')],
            enabledModules: [],
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
}
