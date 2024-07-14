<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Application\Execution;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Schema;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Laravel\Fixture\Order\Order;
use Test\Ecotone\Laravel\Fixture\Order\PlaceOrder;
use Test\Ecotone\Laravel\Fixture\Product\RegisterProduct;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 */
final class EloquentIntegrationTest extends TestCase
{
    public function setUp(): void
    {
        $this->createApplication();

        if (Schema::hasTable('products')) {
            Schema::drop('products');
        }
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('price_amount');
            $table->string('price_currency');
            $table->dateTime('updated_at');
            $table->dateTime('created_at');
        });

        if (Schema::hasTable('orders')) {
            Schema::drop('orders');
        }
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('user_id');
            $table->json('product_ids');
            $table->string('total_price_amount');
            $table->string('total_price_currency');
            $table->boolean('is_cancelled');
            $table->dateTime('updated_at');
            $table->dateTime('created_at');
        });
    }

    public function test_placing_an_order_with_eloquent_model()
    {
        /** @var ConfiguredMessagingSystem $messaging */
        $messaging = $this->createApplication()->make(ConfiguredMessagingSystem::class);

        $messaging->getCommandBus()->send(new RegisterProduct(
            '1',
            'Milk',
            new Money(100, new Currency('USD'))
        ));

        $orderId = $messaging->getCommandBus()->send(new PlaceOrder(
            '1',
            [123, 1323]
        ));

        $this->assertIsInt($orderId);
        $this->assertNotNull(
            Order::find($orderId),
        );
    }

    public function test_executing_command_action()
    {
        /** @var ConfiguredMessagingSystem $messaging */
        $messaging = $this->createApplication()->make(ConfiguredMessagingSystem::class);

        $orderId = $messaging->getCommandBus()->send(new PlaceOrder(
            '1',
            [123, 1323]
        ));
        $this->assertFalse(
            $messaging->getQueryBus()->sendWithRouting('is_cancelled', metadata: ['aggregate.id' => $orderId]),
        );

        $messaging->getCommandBus()->sendWithRouting('cancel_order', metadata: [
            'aggregate.id' => $orderId,
        ]);

        $this->assertTrue(
            $messaging->getQueryBus()->sendWithRouting('is_cancelled', metadata: ['aggregate.id' => $orderId]),
        );
    }

    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
