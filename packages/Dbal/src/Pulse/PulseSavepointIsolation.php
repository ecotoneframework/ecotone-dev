<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Pulse;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSectionIsolation;
use Interop\Queue\ConnectionFactory;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class PulseSavepointIsolation implements PulseSectionIsolation
{
    private const SAVEPOINT = 'ecotone_pulse_section';

    /**
     * @param ConnectionFactory[] $connectionFactories
     */
    public function __construct(private array $connectionFactories, private EcotoneClockInterface $clock)
    {
    }

    public function readOnly(callable $section): array
    {
        $isolated = [];
        foreach ($this->connectionsInTransaction() as $connection) {
            $connection->createSavepoint(self::SAVEPOINT);
            $isolated[] = $connection;
        }

        try {
            return $section();
        } finally {
            foreach (array_reverse($isolated) as $connection) {
                $connection->rollbackSavepoint(self::SAVEPOINT);
            }
        }
    }

    /**
     * @return Connection[]
     */
    private function connectionsInTransaction(): array
    {
        $connections = [];
        foreach ($this->connectionFactories as $connectionFactory) {
            if ($connectionFactory instanceof MultiTenantConnectionFactory && ! $connectionFactory->hasActiveTenant()) {
                continue;
            }

            /** @var DbalContext $context */
            $context = CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($connectionFactory, $this->clock))->createContext();
            $connection = $context->getDbalConnection();
            if ($connection->isTransactionActive() && ! in_array($connection, $connections, true)) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }
}
