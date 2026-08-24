<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting\BlueGreen;

use Ecotone\Api\EventHandler;
use Ecotone\Api\ExecutionPollingMetadata;
use Ecotone\Api\ProjectionName;
use Ecotone\Api\ProjectionV2;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Api\Streaming;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Consumer\InMemory\InMemoryConsumerPositionTracker;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class BlueGreenStreamingProjectionTest extends TestCase
{
    public function test_two_streaming_projections_process_events_independently(): void
    {
        $positionTracker = new InMemoryConsumerPositionTracker();

        $v1 = new #[ProjectionV2('users_v1'), Streaming('streaming_channel')] class {
            public array $users = [];
            public ?string $receivedProjectionName = null;

            #[EventHandler]
            public function onUserCreated(BlueGreenUserCreated $event, #[ProjectionName] string $projectionName): void
            {
                $this->users[$event->id] = $event->name;
                $this->receivedProjectionName = $projectionName;
            }
        };

        $v2 = new #[ProjectionV2('users_v2'), Streaming('streaming_channel')] class {
            public array $users = [];
            public ?string $receivedProjectionName = null;

            #[EventHandler]
            public function onUserCreated(BlueGreenUserCreated $event, #[ProjectionName] string $projectionName): void
            {
                $this->users[$event->id] = $event->name;
                $this->receivedProjectionName = $projectionName;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$v1::class, $v2::class, BlueGreenUserCreated::class],
            [$v1, $v2, ConsumerPositionTracker::class => $positionTracker],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createStreamingChannel('streaming_channel', conversionMediaType: MediaType::createApplicationXPHP()),
                ])
        );

        $channel = $ecotone->getMessageChannel('streaming_channel');
        $channel->send(MessageBuilder::withPayload(new BlueGreenUserCreated('user-1', 'Alice'))
            ->setHeader(MessageHeaders::TYPE_ID, BlueGreenUserCreated::class)
            ->setContentType(MediaType::createApplicationXPHP())
            ->build());
        $channel->send(MessageBuilder::withPayload(new BlueGreenUserCreated('user-2', 'Bob'))
            ->setHeader(MessageHeaders::TYPE_ID, BlueGreenUserCreated::class)
            ->setContentType(MediaType::createApplicationXPHP())
            ->build());

        $ecotone->run('users_v1', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2));

        self::assertCount(2, $v1->users);
        self::assertEquals('users_v1', $v1->receivedProjectionName);
        self::assertCount(0, $v2->users);

        $ecotone->run('users_v2', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2));

        self::assertCount(2, $v2->users);
        self::assertEquals('users_v2', $v2->receivedProjectionName);

        self::assertEquals('Alice', $v1->users['user-1']);
        self::assertEquals('Bob', $v1->users['user-2']);
        self::assertEquals('Alice', $v2->users['user-1']);
        self::assertEquals('Bob', $v2->users['user-2']);
    }
}

class BlueGreenUserCreated
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
