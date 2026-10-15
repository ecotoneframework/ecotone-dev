<?php

declare(strict_types=1);

namespace App\MultiTenant\ReadModel;

use App\MultiTenant\Application\Event\ProductWasRegistered;
use App\MultiTenant\Application\Event\ProductWasUnregistered;
use App\MultiTenant\Application\Product;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Projection(name: 'registered_products')]
#[FromAggregateStream(Product::class)]
final readonly class RegisteredProductList
{
    const TABLE_NAME = 'registered_products';

    #[ProjectionInitialization]
    public function initialize(): void
    {
        if (Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        Schema::create(self::TABLE_NAME, function (Blueprint $table) {
            $table->uuid('product_id');
            $table->string('name');
            $table->dateTime('registered_at');
        });
    }

    #[ProjectionDelete]
    public function remove(): void
    {
        Schema::dropIfExists(self::TABLE_NAME);
    }

    /**
     * @return string[]
     */
    #[QueryHandler('product.getAllRegistered')]
    public function getRegisteredProducts(): array
    {
        return DB::table(self::TABLE_NAME)->orderBy('name')->pluck('name')->all();
    }

    #[EventHandler]
    public function whenProductWasRegistered(
        ProductWasRegistered $event,
        #[Header('timestamp')] int $occurredAt // Accessing Event Metadata
    ): void
    {
        DB::table(self::TABLE_NAME)->insert(
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
        DB::table(self::TABLE_NAME)->where('product_id', $event->productId->toString())->delete();
    }
}