<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\EventStore\Tag;

use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Support\LicensingException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 */
final class TagOperationsWithoutEnterpriseLicenceInMemoryTest extends TestCase
{
    public function test_untagged_append_still_works_without_a_licence(): void
    {
        $eventStore = $this->bootstrapEcotone()->getGateway(EventStore::class);

        $eventStore->appendTo('orders', [new UntaggedOrderPlacedForTagOperationsWithoutEnterpriseLicenceInMemoryTest('o-1')]);

        $this->assertCount(1, $eventStore->load('orders'));
    }

    public function test_loading_events_by_tag_criteria_without_a_licence_throws(): void
    {
        $eventStore = $this->bootstrapEcotone()->getGateway(EventStore::class);

        $this->expectException(LicensingException::class);

        $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'));
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [UntaggedOrderPlacedForTagOperationsWithoutEnterpriseLicenceInMemoryTest::class],
        );
    }
}

/**
 * licence Apache-2.0
 */
final readonly class UntaggedOrderPlacedForTagOperationsWithoutEnterpriseLicenceInMemoryTest
{
    public function __construct(
        public string $orderId,
    ) {
    }
}
