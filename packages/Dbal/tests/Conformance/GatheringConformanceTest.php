<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\GatheredParts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

}
