<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Consumer;

use Ecotone\Dbal\Consumer\DbalConsumerPositionTracker;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * Tests for DBAL consumer position tracker
 * licence Apache-2.0
 */
final class DbalConsumerPositionTrackerTest extends DbalMessagingTestCase
{
    private DbalConsumerPositionTracker $tracker;

    public function setUp(): void
    {
        parent::setUp();

        $connectionFactory = new DbalReconnectableConnectionFactory(
            $this->getConnectionFactory()
        );

        $this->tracker = new DbalConsumerPositionTracker($connectionFactory);

        // Clean up table
        $connection = $this->getConnection();
        if (self::checkIfTableExists($connection, 'ecotone_consumer_position')) {
            $connection->executeStatement('DROP TABLE ecotone_consumer_position');
        }
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

        // Create new tracker instance (simulates reconnection)
        $connectionFactory = new DbalReconnectableConnectionFactory(
            $this->getConnectionFactory()
        );
        $newTracker = new DbalConsumerPositionTracker($connectionFactory);

        // Position should still be there
        $this->assertEquals($position, $newTracker->loadPosition($consumerId));
    }

    public function test_storing_large_position_values()
    {
        $consumerId = 'test_consumer';
        $largePosition = str_repeat('1234567890', 100); // 1000 characters

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

    public function test_custom_table_name()
    {
        $connectionFactory = new DbalReconnectableConnectionFactory(
            $this->getConnectionFactory()
        );
        $customTracker = new DbalConsumerPositionTracker($connectionFactory, 'custom_positions');

        $consumerId = 'test_consumer';
        $position = '12345';

        $customTracker->savePosition($consumerId, $position);
        $this->assertEquals($position, $customTracker->loadPosition($consumerId));

        // Verify custom table was created
        $this->assertTrue(self::checkIfTableExists($this->getConnection(), 'custom_positions'));

        // Clean up
        $this->getConnection()->executeStatement('DROP TABLE custom_positions');
    }

    public function tearDown(): void
    {
        $connection = $this->getConnection();
        if (self::checkIfTableExists($connection, 'ecotone_consumer_position')) {
            $connection->executeStatement('DROP TABLE ecotone_consumer_position');
        }

        parent::tearDown();
    }
}

