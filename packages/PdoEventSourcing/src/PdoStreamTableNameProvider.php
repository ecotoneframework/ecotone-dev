<?php

namespace Ecotone\EventSourcing;

/**
 * Provides table name generation for event streams based on configured persistence strategy.
 * This allows runtime resolution of table names instead of hardcoding sha1 hashing.
 *
 * licence Apache-2.0
 */
interface PdoStreamTableNameProvider
{
    /**
     * Generate the table name for a given stream based on the configured persistence strategy.
     *
     * The table name generation depends on:
     * - The database type (MySQL, MariaDB, PostgreSQL)
     * - The persistence strategy (simple, single, aggregate, partition)
     * - The stream name
     *
     * @param string $streamName The stream name
     * @return string The generated table name
     */
    public function generateTableNameForStream(string $streamName): string;
}
