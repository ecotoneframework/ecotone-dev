<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Gateway;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
interface PartitionDeadLetterGateway
{
    /**
     * @return list<HeldPartition>
     */
    public function list(): array;

    public function replay(string $trackingName, string $partitionKey): void;

    public function delete(string $trackingName, string $partitionKey): void;
}
