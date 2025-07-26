<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeduplicationCommandHandler;

use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class TrackingNameDeduplicationCommandHandler
{
    private int $trackingOneCalled = 0;
    private int $trackingTwoCalled = 0;

    #[Deduplicated(expression: "headers['orderId']", trackingName: 'tracking_one')]
    #[CommandHandler('tracking.handle_with_tracking_one')]
    public function handleWithTrackingOne(): void
    {
        $this->trackingOneCalled++;
    }

    #[Deduplicated(expression: "headers['orderId']", trackingName: 'tracking_two')]
    #[CommandHandler('tracking.handle_with_tracking_two')]
    public function handleWithTrackingTwo(): void
    {
        $this->trackingTwoCalled++;
    }

    #[QueryHandler('tracking.getTrackingOneCallCount')]
    public function getTrackingOneCallCount(): int
    {
        return $this->trackingOneCalled;
    }

    #[QueryHandler('tracking.getTrackingTwoCallCount')]
    public function getTrackingTwoCallCount(): int
    {
        return $this->trackingTwoCalled;
    }

    #[QueryHandler('tracking.reset')]
    public function reset(): void
    {
        $this->trackingOneCalled = 0;
        $this->trackingTwoCalled = 0;
    }
}
