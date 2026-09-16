<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Unit;

use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Event;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class EventTest extends TestCase
{
    public function test_event_created_with_explicit_metadata_preserves_it_through_the_event_store(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [],
            [],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([InMemoryRepositoryBuilder::createDefaultEventSourcedRepository()])
        );

        $metadata = [
            MessageHeaders::MESSAGE_ID => 'uuid',
            MessageHeaders::TIMESTAMP => 123,
        ];

        $ecotone->withEventStream('explicitMetadataStream', [Event::create(new stdClass(), $metadata)]);

        $recordedEvent = $ecotone->getGateway(EventStore::class)->load('explicitMetadataStream')[0];

        $this->assertSame('uuid', $recordedEvent->getMetadata()[MessageHeaders::MESSAGE_ID]);
        $this->assertSame(123, $recordedEvent->getMetadata()[MessageHeaders::TIMESTAMP]);
    }
}
