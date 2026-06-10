<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\TenantSharedConnection;

use Ecotone\Modelling\Attribute\CommandHandler;
use RuntimeException;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;

/**
 * licence Apache-2.0
 */
final class TenantSharedItem
{
    use IsDatabaseModel;

    public PrimaryKey $id;
    public string $name;
}

final class TenantInsertThenFailHandler
{
    #[CommandHandler('tenant_shared_connection.insert_then_fail')]
    public function insertThenFail(): void
    {
        $item = new TenantSharedItem();
        $item->name = 'should-be-rolled-back';
        $item->save();

        throw new RuntimeException('Intentional failure to trigger multi-tenant rollback');
    }
}
