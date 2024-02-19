<?php

declare(strict_types=1);

namespace Test\MultiTenant;

use Symfony\App\MultiTenant\Application\Command\RegisterCustomer;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\TestCase;
use Symfony\App\MultiTenant\Configuration\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
require_once __DIR__ . '/boostrap.php';

final class MultiTenantTest extends TestCase
{
    private QueryBus $queryBus;
    private CommandBus $commandBus;
    private Kernel $kernel;

    public function setUp(): void
    {
        $kernel = new Kernel('dev', true);
        $kernel->boot();
        $app = $kernel->getContainer();
        runMigrationForSymfonyTenants($kernel);

        $this->commandBus = $app->get(CommandBus::class);
        $this->queryBus = $app->get(QueryBus::class);
        $this->kernel = $kernel;
    }

    public function test_run_message_handlers_for_multi_tenant_connection(): void
    {
        $this->commandBus->send(new RegisterCustomer(1, 'John Doe'), metadata: ['tenant' => 'tenant_a']);
        $this->commandBus->send(new RegisterCustomer(2, 'John Doe'), metadata: ['tenant' => 'tenant_a']);
        $this->commandBus->send(new RegisterCustomer(2, 'John Doe'), metadata: ['tenant' => 'tenant_b']);

        $this->assertEquals(
            [1, 2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
        );

        $this->assertEquals(
            [2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
        );
    }

    public function test_transactions_rollbacks_model_changes_and_published_events(): void
    {
        /** This one will be rolled back */
        try {
            $this->commandBus->send(
                new RegisterCustomer(1, 'John Doe'),
                metadata: ['tenant' => 'tenant_a', 'shouldThrowException' => true]
            );
        } catch (\RuntimeException $exception) {
        }

        $this->commandBus->send(
            new RegisterCustomer(2, 'John Doe'),
            metadata: ['tenant' => 'tenant_b']
        );

        /** Consume Messages for Tenant A */
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $output = new ConsoleOutput();
        $input = new ArrayInput(['command' => 'ecotone:run', 'consumerName' => 'notifications', '--stopOnFailure' => true, '--executionTimeLimit' => 1000]);
        // tenant a
        $application->run($input, $output);
        // tenant b
        $application->run($input, $output);

        $this->assertSame(
            0,
            $this->queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_a'])
        );

        $this->assertSame(
            1,
            $this->queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_b'])
        );
    }
}