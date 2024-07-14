<?php

namespace Test\Ecotone\Sqs;

use Enqueue\Sqs\SqsConnectionFactory;
use Exception;
use Interop\Queue\ConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 */
abstract class AbstractConnectionTest extends TestCase
{
    private ?SqsConnectionFactory $connectionFactory = null;

    protected function setUp(): void
    {
        self::cleanUpSqs();
    }


    public function getConnectionFactory(): ConnectionFactory
    {
        if (! $this->connectionFactory) {
            $this->connectionFactory = self::getConnection();
        }

        return $this->connectionFactory;
    }

    public static function getConnection(): SqsConnectionFactory
    {
        return new SqsConnectionFactory(
            getenv('SQS_DSN') ? getenv('SQS_DSN') : 'sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localhost:4566&version=latest'
        );
    }

    public static function cleanUpSqs(): void
    {
        $context = AbstractConnectionTest::getConnection()->createContext();

        foreach (['async', 'sqs'] as $queue) {
            try {
                $context->deleteQueue($context->createQueue($queue));
            } catch (Exception $e) {
            }
        }
    }
}
