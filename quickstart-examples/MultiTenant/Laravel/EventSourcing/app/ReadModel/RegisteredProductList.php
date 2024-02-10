<?php

declare(strict_types=1);

namespace App\MultiTenant\ReadModel;

use App\MultiTenant\Application\Event\ProductWasRegistered;
use App\MultiTenant\Application\Event\ProductWasUnregistered;
use App\MultiTenant\Application\Product;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Projection(name: 'registered_products', fromStreams: Product::class)]
final readonly class RegisteredProductList
{
    const TABLE_NAME = 'registered_products';

    #[ProjectionInitialization]
    public function initialize(): void
    {
        $schemaManager = DB::connection()->getDoctrineConnection()->createSchemaManager();

        if ($schemaManager->tablesExist(self::TABLE_NAME)) {
            return;
        }

        $schemaManager->createTable(
            new Table(self::TABLE_NAME, [
                new Column('product_id', Type::getType(Types::GUID)),
                new Column('name', Type::getType(Types::STRING)),
                new Column('registered_at', Type::getType(Types::DATETIME_IMMUTABLE))
            ])
        );
    }

    #[ProjectionDelete]
    public function remove(): void
    {
        $schemaManager = DB::connection()->getDoctrineConnection()->createSchemaManager();
        if ($schemaManager->tablesExist(self::TABLE_NAME)) {
            return;
        }

        $schemaManager->dropTable(self::TABLE_NAME);
    }

    /**
     * @return string[]
     */
    #[QueryHandler('product.getAllRegistered')]
    public function getRegisteredProducts(): array
    {
        return DB::connection()->getDoctrineConnection()->fetchFirstColumn(
            sprintf(<<<SQL
    SELECT name FROM %s ORDER BY registered_at DESC
SQL, self::TABLE_NAME));
    }

    #[EventHandler]
    public function whenProductWasRegistered(
        ProductWasRegistered $event,
        #[Header('timestamp')] int $occurredAt // Accessing Event Metadata
    ): void
    {
        DB::connection()->getDoctrineConnection()->insert(
            self::TABLE_NAME,
            [
                'product_id' => $event->productId->toString(),
                'name' => $event->name,
                'registered_at' => date('Y-m-d H:i:s', $occurredAt)
            ]
        );
    }

    #[EventHandler]
    public function whenProductWasUnregistered(ProductWasUnregistered $event): void
    {
        DB::connection()->getDoctrineConnection()->delete(
            self::TABLE_NAME,
            [
                'product_id' => $event->productId->toString()
            ]
        );
    }
}