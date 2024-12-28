<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Subscription;

use Ecotone\EventSourcingV2\EventStore\Subscription\EventPage;
use Ecotone\EventSourcingV2\EventStore\Subscription\SubscriptionQuery;

interface PersistentSubscriptions
{
    public const DEFAULT_BATCH_SIZE = 1000;
    public function createSubscription(string $subscriptionName, SubscriptionQuery $subscriptionQuery): void;
    public function deleteSubscription(string $subscriptionName): void;
    public function readFromSubscription(string $subscriptionName): EventPage;
    public function ack(EventPage $page): void;
}