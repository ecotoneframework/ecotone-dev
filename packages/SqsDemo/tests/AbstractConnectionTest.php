<?php

namespace Test\Ecotone\SqsDemo;

use Enqueue\Sqs\SqsConnectionFactory;
use Interop\Queue\ConnectionFactory;
use PHPUnit\Framework\TestCase;

abstract class AbstractConnectionTest extends TestCase
{
    private ?SqsConnectionFactory $connectionFactory = null;

    public function getConnectionFactory(): ConnectionFactory
    {
        if (!$this->connectionFactory) {
            $dsn = getenv('SQS_DSN') ? getenv('SQS_DSN') : 'sqs:?key=aKey&secret=aSecret&region=aRegion&endpoint=localstack-sqs-demo';

            $this->connectionFactory = new SqsConnectionFactory($dsn);
        }

        return $this->connectionFactory;
    }
}
