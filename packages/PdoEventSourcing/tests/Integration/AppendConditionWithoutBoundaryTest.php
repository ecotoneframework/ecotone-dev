<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class AppendConditionWithoutBoundaryTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';

    public function test_a_hand_built_tag_condition_through_the_gateway_requires_the_boundary_to_be_enabled(): void
    {
        $eventStore = $this->bootstrapEcotone()->getGateway(EventStore::class);

        $this->expectException(ConfigurationException::class);

        $eventStore->appendTo(self::STREAM, [new UntaggedEventForAppendConditionWithoutBoundaryTest('o-1')], AppendCondition::fromCapturedVersions([
            ['name' => 'coupon', 'value' => 'SUMMER24', 'expectedVersion' => 0],
        ]));
    }

    public function test_an_aggregate_only_condition_works_without_the_boundary(): void
    {
        $eventStore = $this->bootstrapEcotone()->getGateway(EventStore::class);

        $eventStore->appendTo(self::STREAM, [new UntaggedEventForAppendConditionWithoutBoundaryTest('o-1')], AppendCondition::forAggregate('Order', 'o-1', 0));

        self::assertCount(1, $eventStore->load(self::STREAM));
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [UntaggedEventForAppendConditionWithoutBoundaryTest::class, EventsConverterForAppendConditionWithoutBoundaryTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForAppendConditionWithoutBoundaryTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE]),
            runForProductionEventStore: true,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }
}

final readonly class UntaggedEventForAppendConditionWithoutBoundaryTest
{
    public function __construct(
        public string $orderId,
    ) {
    }
}

final class EventsConverterForAppendConditionWithoutBoundaryTest
{
    #[Converter]
    public function from(UntaggedEventForAppendConditionWithoutBoundaryTest $event): array
    {
        return ['orderId' => $event->orderId];
    }

    #[Converter]
    public function to(array $event): UntaggedEventForAppendConditionWithoutBoundaryTest
    {
        return new UntaggedEventForAppendConditionWithoutBoundaryTest($event['orderId']);
    }
}
