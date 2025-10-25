<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\AggregateMessage;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\Calendar\Calendar;
use Test\Ecotone\Amqp\Fixture\Calendar\ScheduleMeeting;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class CallAggregateAsynchronousEndpointTest extends AmqpMessagingTestCase
{
    public function test_sending_command_to_aggregate(): void
    {
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [Calendar::class],
            containerOrAvailableServices: [
                ...self::getConnectionFactoryReferences(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    AmqpBackedMessageChannelBuilder::create('calendar'),
                ])
        );

        $ecotone
            ->withStateFor(new Calendar('1'))
            ->sendCommand(new ScheduleMeeting('1', '2'))
        ;

        self::assertFalse($ecotone->getMessageChannel('calendar')->receive()->getHeaders()->containsKey(AggregateMessage::CALLED_AGGREGATE_INSTANCE));
    }
}
