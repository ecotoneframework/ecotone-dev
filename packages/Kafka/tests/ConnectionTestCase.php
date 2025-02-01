<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka;

use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 */
abstract class ConnectionTestCase extends TestCase
{
    public static function getConnection(): KafkaBrokerConfiguration
    {
        return KafkaBrokerConfiguration::createWithDefaults([
            getenv('KAFKA_DSN') ?? 'localhost:9094',
        ]);
    }
}
