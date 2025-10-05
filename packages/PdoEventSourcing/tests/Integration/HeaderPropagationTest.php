<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate\Balance;
use Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate\Order;
use Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate\OrderWasPlacedConverter;
use Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate\UuidV4Converter;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class HeaderPropagationTest extends TestCase
{
    public function test_will_provide_propagate_correlation_and_parent_id_header_for_aggregate()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [Order::class, OrderWasPlacedConverter::class],
            [new OrderWasPlacedConverter(), DbalConnectionFactory::class => EventSourcingMessagingTestCase::getConnectionFactory()]
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
            [new OrderWasPlacedConverter(), DbalConnectionFactory::class => EventSourcingMessagingTestCase::getConnectionFactory()]
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

    public function test_with_custom_converter()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [Balance::class, UuidV4Converter::class],
            [new UuidV4Converter(), DbalConnectionFactory::class => EventSourcingMessagingTestCase::getConnectionFactory()]
        );

        $factory = new UuidFactory();
        $balanceId = $factory->fromString(Uuid::uuid4()->toString());
        $userId = $factory->fromString(Uuid::uuid4()->toString());

        $flowTestSupport = $ecotoneTestSupport
            ->sendCommandWithRoutingKey(
                'create',
                $balanceId,
                metadata: ['userId' => $userId]
            );

        $headers = $flowTestSupport->getEventStreamEvents(Balance::class)[0]->getMetadata();
        $this->assertSame($userId->toString(), $headers['userId']);
    }
}
