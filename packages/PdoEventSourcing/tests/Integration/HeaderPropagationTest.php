<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\MessageHeaders;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate\Order;
use Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate\OrderWasPlacedConverter;

/**
 * @internal
 */
final class HeaderPropagationTest extends TestCase
{
    public function test_will_provide_propagate_correlation_and_parent_id_header_for_aggregate()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [Order::class, OrderWasPlacedConverter::class],
            [new OrderWasPlacedConverter()]
        );

        $messageId = Uuid::uuid4()->toString();
        $correlationId = Uuid::uuid4()->toString();

        $flowTestSupport = $ecotoneTestSupport
            ->sendCommandWithRoutingKey(
                'placeOrder',
                Uuid::uuid4()->toString(),
                metadata: [
                    MessageHeaders::MESSAGE_ID => $messageId,
                    MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                ]
            );

        /** From Event Store */
        $headers = $flowTestSupport->getEventStreamEvents(Order::class)[0]->getMetadata();
        $this->assertNotSame($messageId, $headers[MessageHeaders::MESSAGE_ID]);
        $this->assertSame($messageId, $headers[MessageHeaders::PARENT_MESSAGE_ID]);
        $this->assertSame($correlationId, $headers[MessageHeaders::MESSAGE_CORRELATION_ID]);

        /** From Event Bus */
        $headers = $flowTestSupport->getRecordedEventHeaders()[0];
        $this->assertNotSame($messageId, $headers->getMessageId());
        $this->assertSame($messageId, $headers->getParentId());
        $this->assertSame($correlationId, $headers->getCorrelationId());
    }

    public function test_will_propagate_userland_correlation_and_parent_id_header_when_defined()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [Order::class, OrderWasPlacedConverter::class],
            [new OrderWasPlacedConverter()]
        );

        $messageId = Uuid::uuid4()->toString();
        $correlationId = Uuid::uuid4()->toString();

        $flowTestSupport = $ecotoneTestSupport
            ->sendCommandWithRoutingKey(
                'placeOrderAndPropagateMetadata',
                Uuid::uuid4()->toString(),
                metadata: [
                    MessageHeaders::MESSAGE_ID => $messageId,
                    MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                ]
            );

        /** From Event Store */
        $headers = $flowTestSupport->getEventStreamEvents(Order::class)[0]->getMetadata();
        $this->assertNotSame($messageId, $headers[MessageHeaders::MESSAGE_ID]);
        $this->assertSame($messageId, $headers[MessageHeaders::PARENT_MESSAGE_ID]);
        $this->assertSame($correlationId, $headers[MessageHeaders::MESSAGE_CORRELATION_ID]);

        /** From Event Bus */
        $headers = $flowTestSupport->getRecordedEventHeaders()[0]->headers();
        $this->assertNotSame($messageId, $headers[MessageHeaders::MESSAGE_ID]);
        $this->assertSame($messageId, $headers[MessageHeaders::PARENT_MESSAGE_ID]);
        $this->assertSame($correlationId, $headers[MessageHeaders::MESSAGE_CORRELATION_ID]);
    }
}
