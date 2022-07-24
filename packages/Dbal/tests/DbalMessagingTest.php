<?php

namespace Test\Ecotone\Dbal;

use Ecotone\Dbal\DbalConnection;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;
use Interop\Queue\ConnectionFactory;
use PHPUnit\Framework\TestCase;

abstract class DbalMessagingTest extends TestCase
{
    /**
     * @var DbalConnectionFactory|ManagerRegistryConnectionFactory
     */
    private $dbalConnectionFactory;

    public function getConnectionFactory(bool $isRegistry = false): ConnectionFactory
    {
        if (! $this->dbalConnectionFactory) {
            $dsn = getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone';

            $dbalConnectionFactory = new DbalConnectionFactory($dsn);
            $this->dbalConnectionFactory = $isRegistry
                ? DbalConnection::fromConnectionFactory($dbalConnectionFactory)
                : $dbalConnectionFactory;
        }

        return $this->dbalConnectionFactory;
    }

    protected function getReferenceSearchServiceWithConnection()
    {
        return InMemoryReferenceSearchService::createWith([
            DbalConnectionFactory::class => $this->getConnectionFactory(),
        ]);
    }
}
