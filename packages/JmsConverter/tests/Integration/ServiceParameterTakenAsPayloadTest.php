<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Integration;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Conversion\ConversionException;
use Ecotone\Messaging\Handler\MethodInvocationException;
use Ecotone\Test\StaticPsrClock;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Test\Ecotone\JMSConverter\Fixture\ServiceParameter\Basket;

/**
 * licence Apache-2.0
 * @internal
 */
final class ServiceParameterTakenAsPayloadTest extends TestCase
{
    public function test_service_typed_first_parameter_of_a_handler_suggests_marking_it_as_reference(): void
    {
        $handler = new class () {
            #[CommandHandler('basket.clear')]
            public function clear(ClockInterface $clock): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, ClockInterface::class => new StaticPsrClock('2026-03-01 12:00:00')],
            ServiceConfiguration::createWithDefaults()->withModulePackages([ModulePackageList::JMS_CONVERTER_PACKAGE]),
        );

        $this->expectException(MethodInvocationException::class);
        $this->expectExceptionMessage('If $clock is a service rather than the message payload, mark it with #[Reference].');

        $ecotone->sendCommandWithRouting('basket.clear');
    }

    public function test_service_typed_first_parameter_of_an_aggregate_handler_suggests_marking_it_as_reference(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [Basket::class],
            [ClockInterface::class => new StaticPsrClock('2026-03-01 12:00:00')],
            ServiceConfiguration::createWithDefaults()->withModulePackages([ModulePackageList::JMS_CONVERTER_PACKAGE]),
        );
        $ecotone->sendCommandWithRouting('basket.create', 'basket-1');

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Payload of the message sent to ' . Basket::class . ' could not be converted into Psr\Clock\ClockInterface, the type of the first handler parameter without an attribute. If that parameter is a service rather than the message payload, mark it with #[Reference].');

        $ecotone->sendCommandWithRouting('basket.clear', metadata: ['aggregate.id' => 'basket-1']);
    }
}
