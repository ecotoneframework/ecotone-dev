<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\MethodInvocationException;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\JMSConverter\Fixture\InterfacePayload\Basket;
use Test\Ecotone\JMSConverter\Fixture\InterfacePayload\BasketContentChanged;
use Test\Ecotone\JMSConverter\Fixture\InterfacePayload\EventSourcedBasket;
use Test\Ecotone\JMSConverter\Fixture\InterfacePayload\ProductAddedToBasket;
use Test\Ecotone\JMSConverter\Fixture\InterfacePayload\ProductRemovedFromBasket;

/**
 * licence Apache-2.0
 * @internal
 */
final class InterfaceTypedPayloadTest extends TestCase
{
    public function test_interface_typed_asynchronous_handler_receives_the_concrete_event_after_serialisation(): void
    {
        $listener = new class () {
            public array $received = [];

            #[Asynchronous('async')]
            #[EventHandler(endpointId: 'onBasketContentChanged')]
            public function onChange(BasketContentChanged $event): void
            {
                $this->received[] = $event;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([$listener::class], [$listener], $this->serialisingAsyncChannel());

        $ecotone
            ->publishEvent(new ProductAddedToBasket('basket-1', 'product-1'))
            ->publishEvent(new ProductRemovedFromBasket('basket-1', 'product-1'))
            ->run('async');

        $this->assertEquals(
            [new ProductAddedToBasket('basket-1', 'product-1'), new ProductRemovedFromBasket('basket-1', 'product-1')],
            $listener->received,
        );
    }

    public function test_aggregate_event_handler_typed_on_an_interface_receives_the_concrete_event_after_serialisation(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting([Basket::class], [], $this->serialisingAsyncChannel());

        $ecotone
            ->sendCommandWithRouting('basket.addProduct', ['basketId' => 'basket-1', 'productId' => 'product-1'])
            ->run('async');

        $this->assertSame([ProductAddedToBasket::class], $ecotone->getAggregate(Basket::class, 'basket-1')->changes);
    }

    public function test_projection_handler_typed_on_an_interface_receives_the_concrete_stored_event(): void
    {
        $projection = new #[Projection('basket_changes')] #[FromAggregateStream(EventSourcedBasket::class)] class () {
            public array $received = [];

            #[EventHandler]
            public function onChange(BasketContentChanged $event): void
            {
                $this->received[] = $event;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore([EventSourcedBasket::class, $projection::class], [$projection]);

        $ecotone->sendCommandWithRouting('eventSourcedBasket.addProduct', ['basketId' => 'basket-1', 'productId' => 'product-1']);

        $this->assertEquals([new ProductAddedToBasket('basket-1', 'product-1')], $projection->received);
    }

    public function test_interface_typed_handler_without_concrete_type_information_says_how_to_type_the_parameter(): void
    {
        $listener = new class () {
            #[Asynchronous('async')]
            #[EventHandler('basket.changed', endpointId: 'onBasketChanged')]
            public function onChange(BasketContentChanged $event): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([$listener::class], [$listener], $this->serialisingAsyncChannel());
        $ecotone->publishEventWithRouting('basket.changed', ['basketId' => 'basket-1', 'productId' => 'product-1']);

        $this->expectException(MethodInvocationException::class);
        $this->expectExceptionMessage('Parameter $event is typed with ' . BasketContentChanged::class . ', which cannot be instantiated, and the message does not name a concrete class in its __TypeId__ header. Type the parameter with a concrete class or a union of concrete classes, or send an object instead of an array.');

        $ecotone->run('async');
    }

    private function serialisingAsyncChannel(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()
            ->withModulePackages([ModulePackageList::JMS_CONVERTER_PACKAGE])
            ->withExtensionObjects([
                SimpleMessageChannelBuilder::createQueueChannel('async', conversionMediaType: MediaType::createApplicationJson()),
            ]);
    }
}
