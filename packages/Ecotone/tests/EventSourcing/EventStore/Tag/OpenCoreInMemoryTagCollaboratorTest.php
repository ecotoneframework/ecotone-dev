<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\EventStore\Tag;

use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\EventSourcing\EventStore\InMemoryEventStore;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Event;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 */
final class OpenCoreInMemoryTagCollaboratorTest extends TestCase
{
    public function test_untagged_append_still_works_without_a_licence(): void
    {
        $eventStore = new InMemoryEventStore();

        $eventStore->appendTo('orders', [Event::createWithType('OrderPlaced', ['orderId' => 'o-1'])]);

        $this->assertCount(1, $eventStore->load('orders'));
    }

    public function test_loading_events_by_tag_criteria_without_a_licence_throws(): void
    {
        $eventStore = new InMemoryEventStore();

        $this->expectException(LicensingException::class);

        $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'));
    }
}