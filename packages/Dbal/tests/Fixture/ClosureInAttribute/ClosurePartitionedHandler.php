<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ClosureInAttribute;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\PartitionDeadLetter;
use RuntimeException;

/**
 * licence Apache-2.0
 */
final class ClosurePartitionedHandler
{
    public array $placed = [];

    #[PartitionDeadLetter(expression: static function (ClosurePartitionedPlaceOrder $command): string {
        return $command->orderId;
    })]
    #[Asynchronous('closurePartition.orders')]
    #[CommandHandler(endpointId: 'closurePartition.place.endpoint')]
    public function place(ClosurePartitionedPlaceOrder $command): void
    {
        if ($command->item === 'broken') {
            throw new RuntimeException('cannot place broken');
        }

        $this->placed[] = "{$command->orderId}:{$command->item}";
    }
}
