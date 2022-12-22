<?php

namespace Test\Ecotone\Sqs;

use Enqueue\Sqs\SqsConnectionFactory;
use Interop\Queue\ConnectionFactory;
use PHPUnit\Framework\TestCase;

abstract class AbstractConnectionTest extends TestCase
{
    private ?SqsConnectionFactory $connectionFactory = null;

    public function getConnectionFactory(): ConnectionFactory
    {
        if (! $this->connectionFactory) {
            $dsn = getenv('SQS_DSN') ? getenv('SQS_DSN') : 'sqs:?key=key&secret=secret&region=us-east-1&endpoint=http://localstack:4576&version=latest';

            $this->connectionFactory = new SqsConnectionFactory($dsn);
        }

        return $this->connectionFactory;
    }
}
