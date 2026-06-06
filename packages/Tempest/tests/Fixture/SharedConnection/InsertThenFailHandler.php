<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\SharedConnection;

use Ecotone\Modelling\Attribute\CommandHandler;
use RuntimeException;

/**
 * licence Apache-2.0
 */
final class InsertThenFailHandler
{
    #[CommandHandler('shared_connection.insert_then_fail')]
    public function insertThenFail(): void
    {
        $item = new SharedConnectionItem();
        $item->name = 'should-be-rolled-back';
        $item->save();

        throw new RuntimeException('Intentional failure to trigger rollback');
    }
}
