<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Support\LicensingException;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class OpenCoreAppendConditionTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';

    public function test_a_hand_built_tag_condition_through_the_gateway_requires_enterprise(): void
    {
        $eventStore = $this->bootstrapEcotone()->getGateway(EventStore::class);

        $this->expectException(LicensingException::class);

        $eventStore->appendTo(self::STREAM, [new UntaggedEventForOpenCoreAppendConditionTest('o-1')], AppendCondition::fromCapturedVersions([
            ['name' => 'coupon', 'value' => 'SUMMER24', 'expectedVersion' => 0],
        ]));
    }

    public function test_an_aggregate_only_condition_works_without_a_licence(): void
    {
        $eventStore = $this->bootstrapEcotone()->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [new UntaggedEventForOpenCoreAppendConditionTest('o-1')], AppendCondition::forAggregate('Order', 'o-1', 0));

        self::assertCount(1, $eventStore->load(self::STREAM));
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [UntaggedEventForOpenCoreAppendConditionTest::class, EventsConverterForOpenCoreAppendConditionTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForOpenCoreAppendConditionTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE]),
            runForProductionEventStore: true,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }
}

final readonly class UntaggedEventForOpenCoreAppendConditionTest
{
    public function __construct(
        public string $orderId,
    ) {
    }
}

final class EventsConverterForOpenCoreAppendConditionTest
{
    #[Converter]
    public function from(UntaggedEventForOpenCoreAppendConditionTest $event): array
    {
        return ['orderId' => $event->orderId];
    }

    #[Converter]
    public function to(array $event): UntaggedEventForOpenCoreAppendConditionTest
    {
        return new UntaggedEventForOpenCoreAppendConditionTest($event['orderId']);
    }
}
