<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Unit;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Modelling\Fixture\IdentifierMapping\OrderProcess;
use Test\Ecotone\Modelling\Fixture\IdentifierMapping\OrderProcessWithMethodBasedIdentifier;
use Test\Ecotone\Modelling\Fixture\IdentifierMapping\OrderStarted;
use Test\Ecotone\Modelling\Fixture\IdentifierMapping\OrderStartedAsynchronous;

final class IdentifierMappingTest extends TestCase
{
    /**
     * @dataProvider sagasTypes
     */
    public function test_mapping_using_target_identifier_for_events(string $sagaClass): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$sagaClass],
        );

        $this->assertEquals(
            '123',
            $ecotoneLite
                ->publishEvent(new OrderStarted('123'))
                ->getSaga($sagaClass, '123')
                ->getOrderId()
        );
    }

    /**
     * @dataProvider sagasTypes
     */
    public function test_mapping_using_target_identifier_for_events_when_endpoint_is_asynchronous(string $sagaClass): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$sagaClass],
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('async')
            ]
        );

        $this->assertEquals(
            '123',
            $ecotoneLite
                ->publishEvent(new OrderStartedAsynchronous('123'))
                ->run('async')
                ->getSaga($sagaClass, '123')
                ->getOrderId()
        );
    }

    public static function sagasTypes(): iterable
    {
        yield "Property based identifier" => [OrderProcess::class];
        yield "Method based identifier" => [OrderProcessWithMethodBasedIdentifier::class];
    }
}