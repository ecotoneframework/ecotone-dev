<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Api\Sqs\SqsConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class GatheringTimeoutTest extends TestCase
{
    private const ENTERPRISE_LICENCE = 'eyJzaWduYXR1cmUiOiJxRmtrSDdCQkxzdUlTRzExZmVXeHVKVTY3bGZScmFSTFBpb0VUaGdoZTAyYWRjajlVRkNWWXhvaGl4YXhoaEFlNzU5aExOT0R5NXo1T2N2eFppUGQyemRRblYrb1dNZjFwM2lqbFN4cUg1MDhVV1wvOG90TllkM28wMVRZRmdMSjU4RXJzYThLcUJib0Z1MmhaZW9lczQ3elM2VHR5TzFGV2pOeWxlbG1aRWNLUHFydFhzOTNcL1YxY0wwWVg4UFBOZDl6bVRPOWxyc3UzM3F1anBXVlB4eVRMTW4yK3lPc3Jxa2pJZDArSU1LSWl0dG1IZGlBcml6NnZIXC93RnFKTU5Od2JKWnFsUXRNa3pQc3pvRlFseVgyZnNPTG5CTWNCajA5S0VnNjhVcG14OVwvUnBHZXBNV2UzcThYeFZMa3laOEtXdndEN2JBZDZPSXVoejlxRThyamVQVVVsbUxybm0wd3ZFYjV6UVZEbU5OY21uZnNkMEYyazJhSldySzc5VXlEWVY3UFNvVkZiM0diV0VNN2p1STVFV2w5cTVaWXJiOGxFcmxTTmNpYmQwbCttMWkzUWk1TnZhU1RscHBTV1BGS0g4c2x5dDhKRzV4YXZqQlJFU3BJWGVDNE9VREdoZDRJNEhaaEpDXC9Wcm9Nb0FRNzBMaXRrUHcxWkdWQ1dqTURucHdNdWRyWThpMVQwak5MRytXYlpYckpvRHZxd0x4SENKdU9EWnhuYVNISkVVSVFndGViUXZPRURIKzBjN0o0VVJMWkV2NnU2d2tcL1F6TzlPVnVVS1BubDkzK1dVam8xVDZqOEJ0MEtSeHhsMmtBY2pQcE9wWTg5Q25BQlBtckF4THJhYkxXeThpZmxVQ0cwUjNmZzB4UnpiMjlMSENvUW95b2NmRStzYjhFYz0iLCJkYXRhIjp7ImVtYWlsIjoiam9obnlAd3AucGwiLCJleHBpcmVBdCI6IjIwMzItMTAtMTEgMDA6MDA6MDAiLCJpc0VudGVycHJpc2VQbHVzIjpmYWxzZX19';

    public function test_gathered_timeout_exceeding_sqs_fifteen_minute_limit_is_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Splitter('parts', endpointId: 'parts.split', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered(timeout: new TimeSpan(minutes: 15, milliseconds: 1))]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
            }
        };

        $this->expectExceptionMessage("Gathered handler 'summary' timeout of 900001 milliseconds exceeds asynchronous channel 'summaries' maximum delay of 900000 milliseconds. Reduce timeout in #[Gathered], use a channel with a longer maximum delay, or remove timeout.");

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, SqsConnectionFactory::class => new SqsConnectionFactory(getenv('SQS_DSN'))],
            extensionObjects: [SqsBackedMessageChannelBuilder::create('summaries')],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
    }

    public function test_gathered_timeout_on_sqs_fifo_queue_is_refused_at_bootstrap(): void
    {
        $handler = new class () {
            #[Splitter('parts', endpointId: 'parts.split', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered(timeout: new TimeSpan(minutes: 1))]
            #[Asynchronous('summaries.fifo')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
            }
        };

        $this->expectExceptionMessage("Gathered handler 'summary' has a timeout but asynchronous channel 'summaries.fifo' cannot hold delayed messages. Use a channel builder implementing DelayedMessageChannelBuilder with delay support, enable delays on this channel, or remove timeout from #[Gathered].");

        EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, SqsConnectionFactory::class => new SqsConnectionFactory(getenv('SQS_DSN'))],
            extensionObjects: [SqsBackedMessageChannelBuilder::create('summaries.fifo')],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
    }

    public function test_gathered_timeout_at_sqs_fifteen_minute_limit_is_accepted_at_bootstrap(): void
    {
        $handler = new class () {
            #[Splitter('parts', endpointId: 'parts.split', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[InternalHandler('work')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered(timeout: new TimeSpan(minutes: 15))]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
            }
        };



        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, SqsConnectionFactory::class => new SqsConnectionFactory(getenv('SQS_DSN'))],
            extensionObjects: [SqsBackedMessageChannelBuilder::create('summaries')],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary'));
    }

}
