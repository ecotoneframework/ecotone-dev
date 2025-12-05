<?php

namespace Modelling\Unit;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Modelling\Fixture\MultipleMessageHandlers\InvoiceOrder;
use Test\Ecotone\Modelling\Fixture\MultipleMessageHandlers\ProductBecameBillable;
use Test\Ecotone\Modelling\Fixture\MultipleMessageHandlers\ProductDebtorWasChanged;

class MultipleMessageHandlersTest extends TestCase
{
    public function test_multiple_message_handlers(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces(['Test\Ecotone\Modelling\Fixture\MultipleMessageHandlers'])
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('invoiceOrder')
                ])
            ,
        );

        $ecotone->publishEvent(new ProductBecameBillable(productId: 'product-1', debtor: 'debtor-1'));

        self::assertEquals(['product-1'], $ecotone->getAggregate(InvoiceOrder::class, 'debtor-1')->products());

        $ecotone->publishEvent(new ProductDebtorWasChanged(productId: 'product-1', oldDebtor: 'debtor-1', newDebtor: 'debtor-2'));

        self::assertEquals([], $ecotone->getAggregate(InvoiceOrder::class, 'debtor-1')->products());
        self::assertEquals(['product-1'], $ecotone->getAggregate(InvoiceOrder::class, 'debtor-1')->products());
    }
}
