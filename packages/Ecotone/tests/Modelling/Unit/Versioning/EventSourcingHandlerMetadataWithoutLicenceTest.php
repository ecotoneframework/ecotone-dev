<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Unit\Versioning;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\WithAggregateVersioning;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class EventSourcingHandlerMetadataWithoutLicenceTest extends TestCase
{
    public function test_reading_metadata_in_event_sourcing_handler_without_licence_names_the_open_source_alternative(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting([BasketReadingRecordTime::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Using multiple parameters for Event Sourcing Handler: ' . BasketReadingRecordTime::class . '::applyCreated is part of Enterprise features. Without Enterprise, keep a single event parameter and carry the value you need (for example the time of the change) in the event itself.');

        $ecotone->sendCommandWithRouting('basketReadingRecordTime.create', 'basket-1');
    }
}

/**
 * licence Apache-2.0
 * @internal
 */
#[EventSourcingAggregate]
final class BasketReadingRecordTime
{
    use WithAggregateVersioning;

    #[Identifier]
    public string $basketId;

    public ?int $lastChangedAt = null;

    #[CommandHandler('basketReadingRecordTime.create')]
    public static function create(string $basketId): array
    {
        return [new BasketReadingRecordTimeCreated($basketId)];
    }

    #[EventSourcingHandler]
    public function applyCreated(BasketReadingRecordTimeCreated $event, #[Header(MessageHeaders::TIMESTAMP)] int $recordedAt): void
    {
        $this->basketId = $event->basketId;
        $this->lastChangedAt = $recordedAt;
    }
}

/**
 * licence Apache-2.0
 * @internal
 */
final class BasketReadingRecordTimeCreated
{
    public function __construct(public string $basketId)
    {
    }
}
