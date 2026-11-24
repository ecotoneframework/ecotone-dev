<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Test;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Delayed;
use Ecotone\Api\Attribute\TimeToLive;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\Duration;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DurationOnMessageChannelTest extends TestCase
{
    public function test_time_to_live_declared_as_duration_reaches_the_channel_in_milliseconds(): void
    {
        $handler = new class () {
            #[Asynchronous('async')]
            #[TimeToLive(new Duration(minutes: 5))]
            #[CommandHandler('offer.send', endpointId: 'sendOffer')]
            public function send(string $offerId): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('async')],
            enabledModules: [],
            testConfiguration: TestConfiguration::createWithDefaults()->withSpyOnChannel('async'),
        );

        $headers = $ecotone
            ->sendCommandWithRouting('offer.send', 'offer-1')
            ->popRecordedMessagesFrom('async')[0]->getHeaders()->headers();

        $this->assertSame(300_000, $headers[MessageHeaders::TIME_TO_LIVE]);
    }

    public function test_messages_delayed_up_to_a_duration_are_released_without_moving_the_clock(): void
    {
        $handler = new class () {
            public array $reminded = [];

            #[Asynchronous('async')]
            #[Delayed(new Duration(hours: 1))]
            #[CommandHandler('basket.remind', endpointId: 'remindAboutBasket')]
            public function remind(string $basketId): void
            {
                $this->reminded[] = $basketId;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('async', true)],
            enabledModules: [],
            initialTime: new DateTimeImmutable('2026-03-01 12:00:00'),
        );
        $ecotone->sendCommandWithRouting('basket.remind', 'basket-1');

        $ecotone->testSupportGateway->releaseMessagesAwaitingFor('async', Duration::minutes(59));
        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'async', 'handledMessageLimit' => 1, 'executionTimeLimit' => 1]);
        $this->assertSame([], $handler->reminded);

        $ecotone->testSupportGateway->releaseMessagesAwaitingFor('async', Duration::hours(1));
        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'async', 'handledMessageLimit' => 1, 'executionTimeLimit' => 1]);
        $this->assertSame(['basket-1'], $handler->reminded);
    }
}
