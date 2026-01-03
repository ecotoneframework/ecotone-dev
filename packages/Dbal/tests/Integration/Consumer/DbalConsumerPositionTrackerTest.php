<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Consumer;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Consumer\DbalConsumerPositionTracker;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * Tests for DBAL consumer position tracker
 * licence Apache-2.0
 * @internal
 */
final class DbalConsumerPositionTrackerTest extends DbalMessagingTestCase
{
    private DbalConsumerPositionTracker $tracker;
    private DocumentStore $documentStore;

    public function setUp(): void
    {
        parent::setUp();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withAutomaticTableInitialization(true),
                ])
        );

        $this->documentStore = $ecotoneLite->getGateway(DocumentStore::class);
        $this->tracker = new DbalConsumerPositionTracker($this->documentStore);
    }

    public function test_saving_and_loading_position()
    {
        $consumerId = 'test_consumer';
        $position = '12345';

        // Initially no position
        $this->assertNull($this->tracker->loadPosition($consumerId));

        // Save position
        $this->tracker->savePosition($consumerId, $position);

        // Load position
        $this->assertEquals($position, $this->tracker->loadPosition($consumerId));
    }

    public function test_updating_existing_position()
    {
        $consumerId = 'test_consumer';

        // Save initial position
        $this->tracker->savePosition($consumerId, '100');
        $this->assertEquals('100', $this->tracker->loadPosition($consumerId));

        // Update position
        $this->tracker->savePosition($consumerId, '200');
        $this->assertEquals('200', $this->tracker->loadPosition($consumerId));
    }

    public function test_multiple_consumers()
    {
        $consumer1 = 'consumer_1';
        $consumer2 = 'consumer_2';

        $this->tracker->savePosition($consumer1, '100');
        $this->tracker->savePosition($consumer2, '200');

        $this->assertEquals('100', $this->tracker->loadPosition($consumer1));
        $this->assertEquals('200', $this->tracker->loadPosition($consumer2));
    }

    public function test_deleting_position()
    {
        $consumerId = 'test_consumer';

        // Save position
        $this->tracker->savePosition($consumerId, '100');
        $this->assertEquals('100', $this->tracker->loadPosition($consumerId));

        // Delete position
        $this->tracker->deletePosition($consumerId);
        $this->assertNull($this->tracker->loadPosition($consumerId));
    }

    public function test_table_creation_is_idempotent()
    {
        $consumerId = 'test_consumer';

        // First save creates table
        $this->tracker->savePosition($consumerId, '100');

        // Second save should not fail
        $this->tracker->savePosition($consumerId, '200');

        $this->assertEquals('200', $this->tracker->loadPosition($consumerId));
    }

    public function test_position_survives_connection_reconnect()
    {
        $consumerId = 'test_consumer';
        $position = '12345';

        // Save with first tracker instance
        $this->tracker->savePosition($consumerId, $position);

        // Create new EcotoneLite instance (simulates reconnection)
        $newEcotoneLite = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withAutomaticTableInitialization(true),
                ])
        );

        $newDocumentStore = $newEcotoneLite->getGateway(DocumentStore::class);
        $newTracker = new DbalConsumerPositionTracker($newDocumentStore);

        // Position should still be there
        $this->assertEquals($position, $newTracker->loadPosition($consumerId));
    }

    public function test_storing_large_position_values()
    {
        $consumerId = 'test_consumer';
        $largePosition = '12345678900000';

        $this->tracker->savePosition($consumerId, $largePosition);
        $this->assertEquals($largePosition, $this->tracker->loadPosition($consumerId));
    }

    public function test_concurrent_updates_to_same_consumer()
    {
        $consumerId = 'test_consumer';

        // Simulate concurrent updates
        $this->tracker->savePosition($consumerId, '100');
        $this->tracker->savePosition($consumerId, '200');
        $this->tracker->savePosition($consumerId, '300');

        // Last write wins
        $this->assertEquals('300', $this->tracker->loadPosition($consumerId));
    }

    public function tearDown(): void
    {
        // Clean up consumer positions collection
        $this->documentStore->dropCollection('consumer_positions');

        parent::tearDown();
    }
}
