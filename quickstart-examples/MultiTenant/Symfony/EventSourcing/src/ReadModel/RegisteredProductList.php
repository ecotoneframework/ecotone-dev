<?php

declare(strict_types=1);

namespace App\MultiTenant\ReadModel;

use App\MultiTenant\Application\Event\ProductWasRegistered;
use App\MultiTenant\Application\Event\ProductWasUnregistered;
use App\MultiTenant\Application\Product;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Ecotone\Api\Dbal\MultiTenantConnection;
use Ecotone\Api\Projection;
use Ecotone\Api\FromStream;
use Ecotone\Api\ProjectionDelete;
use Ecotone\Api\ProjectionInitialization;
use Ecotone\Api\Header;
use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Projection(name: 'registered_products')]
#[FromStream(Product::class)]
final readonly class RegisteredProductList
{
    const TABLE_NAME = 'registered_products';

    #[ProjectionInitialization]
    public function initialize(#[MultiTenantConnection] Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();

        if ($schemaManager->tablesExist([self::TABLE_NAME])) {
            return;
        }

        $schemaManager->createTable(
            new Table(self::TABLE_NAME, [
                new Column('product_id', Type::getType(Types::GUID)),
                new Column('name', Type::getType(Types::STRING), ['length' => 255]),
                new Column('registered_at', Type::getType(Types::DATETIME_IMMUTABLE), ['precision' => 6])
            ])
        );
    }

    #[ProjectionDelete]
    public function remove(#[MultiTenantConnection] Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();
        if ($schemaManager->tablesExist([self::TABLE_NAME])) {
            return;
        }

        $schemaManager->dropTable(self::TABLE_NAME);
    }

    /**
     * @return string[]
     */
    #[QueryHandler('product.getAllRegistered')]
    public function getRegisteredProducts(#[MultiTenantConnection] Connection $connection): array
    {
        return $connection->fetchFirstColumn(
            sprintf(<<<SQL
    SELECT name FROM %s ORDER BY name ASC
SQL, self::TABLE_NAME));
    }

    #[EventHandler]
    public function whenProductWasRegistered(
        ProductWasRegistered $event,
        #[Header('timestamp')] int $occurredAt, // Accessing Event Metadata,
        #[MultiTenantConnection] Connection $connection
    ): void
    {
        $connection->insert(
            self::TABLE_NAME,
            [
                'product_id' => $event->productId->toString(),
                'name' => $event->name,
                'registered_at' => date('Y-m-d H:i:s', $occurredAt)
            ]
        );
    }

    #[EventHandler]
    public function whenProductWasUnregistered(
        ProductWasUnregistered $event,
        #[MultiTenantConnection] Connection $connection
    ): void
    {
        $connection->delete(
            self::TABLE_NAME,
            [
                'product_id' => $event->productId->toString()
            ]
        );
    }
}