<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\MultiTenant;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use PHPUnit\Framework\TestCase;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Application;

require __DIR__ . '/boostrap.php';

final class MultiTenantTest extends TestCase
{
    private Application $app;
    private QueryBus $queryBus;
    private CommandBus $commandBus;

    public function setUp(): void
    {
        $app = require __DIR__ . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        runMigrationForTenants(DB::connection('tenant_a_connection'), DB::connection('tenant_b_connection'));
        $this->app = $app;
        $this->queryBus = $app->get(QueryBus::class);
        $this->commandBus = $app->get(CommandBus::class);
    }

    public function test_run_message_handlers_for_multi_tenant_connection()
    {
        $this->commandBus->send(new RegisterCustomer(1, 'John Doe'), metadata: ['tenant' => 'tenant_a']);
        $this->commandBus->send(new RegisterCustomer(2, 'John Doe'), metadata: ['tenant' => 'tenant_a']);
        $this->commandBus->send(new RegisterCustomer(2, 'John Doe'), metadata: ['tenant' => 'tenant_b']);

        $this->assertSame(
            [1, 2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
        );

        $this->assertSame(
            [2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
        );
    }

    public function test_using_dbal_based_business_interfaces()
    {
        $this->commandBus->sendWithRouting(
            'customer.register_with_business_interface',
            new RegisterCustomer(1, 'John Doe'),
            metadata: ['tenant' => 'tenant_a']
        );

        $this->assertSame(
            [1],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
        );

        $this->assertSame(
            [],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
        );
    }
}