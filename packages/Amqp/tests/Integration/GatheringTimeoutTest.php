<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use DateTimeImmutable;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Scheduling\TimeSpan;
use Enqueue\AmqpTools\RabbitMqDlxDelayStrategy;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class GatheringTimeoutTest extends TestCase
{
    private const ENTERPRISE_LICENCE = 'eyJzaWduYXR1cmUiOiJxRmtrSDdCQkxzdUlTRzExZmVXeHVKVTY3bGZScmFSTFBpb0VUaGdoZTAyYWRjajlVRkNWWXhvaGl4YXhoaEFlNzU5aExOT0R5NXo1T2N2eFppUGQyemRRblYrb1dNZjFwM2lqbFN4cUg1MDhVV1wvOG90TllkM28wMVRZRmdMSjU4RXJzYThLcUJib0Z1MmhaZW9lczQ3elM2VHR5TzFGV2pOeWxlbG1aRWNLUHFydFhzOTNcL1YxY0wwWVg4UFBOZDl6bVRPOWxyc3UzM3F1anBXVlB4eVRMTW4yK3lPc3Jxa2pJZDArSU1LSWl0dG1IZGlBcml6NnZIXC93RnFKTU5Od2JKWnFsUXRNa3pQc3pvRlFseVgyZnNPTG5CTWNCajA5S0VnNjhVcG14OVwvUnBHZXBNV2UzcThYeFZMa3laOEtXdndEN2JBZDZPSXVoejlxRThyamVQVVVsbUxybm0wd3ZFYjV6UVZEbU5OY21uZnNkMEYyazJhSldySzc5VXlEWVY3UFNvVkZiM0diV0VNN2p1STVFV2w5cTVaWXJiOGxFcmxTTmNpYmQwbCttMWkzUWk1TnZhU1RscHBTV1BGS0g4c2x5dDhKRzV4YXZqQlJFU3BJWGVDNE9VREdoZDRJNEhaaEpDXC9Wcm9Nb0FRNzBMaXRrUHcxWkdWQ1dqTURucHdNdWRyWThpMVQwak5MRytXYlpYckpvRHZxd0x4SENKdU9EWnhuYVNISkVVSVFndGViUXZPRURIKzBjN0o0VVJMWkV2NnU2d2tcL1F6TzlPVnVVS1BubDkzK1dVam8xVDZqOEJ0MEtSeHhsMmtBY2pQcE9wWTg5Q25BQlBtckF4THJhYkxXeThpZmxVQ0cwUjNmZzB4UnpiMjlMSENvUW95b2NmRStzYjhFYz0iLCJkYXRhIjp7ImVtYWlsIjoiam9obnlAd3AucGwiLCJleHBpcmVBdCI6IjIwMzItMTAtMTEgMDA6MDA6MDAiLCJpc0VudGVycHJpc2VQbHVzIjpmYWxzZX19';

    public function test_gathered_timeout_is_accepted_on_amqp_delayed_channel(): void
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

            #[Gathered(timeout: new TimeSpan(days: 60))]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
            }
        };



        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, AmqpExtConnectionFactory::class => new AmqpExtConnectionFactory(getenv('RABBIT_HOST'))],
            extensionObjects: [AmqpBackedMessageChannelBuilder::create('summaries')],
            enabledModules: [ModulePackageList::AMQP_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary.handler'));
    }

    public function test_gathered_timeout_trusts_custom_amqp_delay_strategy_unknown_maximum_as_unbounded(): void
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

            #[Gathered(timeout: new TimeSpan(days: 60))]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
            }
        };



        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, AmqpExtConnectionFactory::class => new AmqpExtConnectionFactory(getenv('RABBIT_HOST')), 'custom.delay' => new RabbitMqDlxDelayStrategy()],
            extensionObjects: [AmqpBackedMessageChannelBuilder::create('summaries')->withDelayStrategy('custom.delay')],
            enabledModules: [ModulePackageList::AMQP_PACKAGE],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );

        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary.handler'));
    }

}
