<?php

declare(strict_types=1);

namespace Test\SingleTenant;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\App\SingleTenant\Application\Command\RegisterCustomer;
use Symfony\App\SingleTenant\Application\Customer;
use Symfony\App\SingleTenant\Application\External\ExternalRegistrationHappened;
use Symfony\App\SingleTenant\Configuration\EcotoneConfiguration;
use Symfony\App\SingleTenant\Configuration\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require_once __DIR__ . '/boostrap.php';

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class SingleTenantTest extends TestCase
{
    private QueryBus $queryBus;
    private CommandBus $commandBus;
    private Kernel $kernel;

    public function setUp(): void
    {
        $kernel = new Kernel('dev', true);
        $kernel->boot();
        $app = $kernel->getContainer();
        runMigrationForSymfony($kernel);

        $this->commandBus = $app->get(CommandBus::class);
        $this->queryBus = $app->get(QueryBus::class);
        $this->kernel = $kernel;
    }

    protected function tearDown(): void
    {
        restore_exception_handler();
    }

    public function test_run_message_handlers_for_single_tenant(): void
    {
        $this->commandBus->send(new RegisterCustomer(1, 'John Doe'));
        $this->commandBus->send(new RegisterCustomer(2, 'John Doe'));

        $this->assertEquals(
            [1, 2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered')
        );
    }

    public function test_transactions_rollbacks_model_changes_and_published_events(): void
    {
        /** This one will be rolled back */
        try {
            $this->commandBus->send(
                new RegisterCustomer(1, 'John Doe'),
                metadata: ['shouldThrowException' => true]
            );
        } catch (RuntimeException $exception) {
        }

        $this->commandBus->send(
            new RegisterCustomer(2, 'John Doe'),
        );

        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $output = new ConsoleOutput();
        $input = new ArrayInput(['command' => 'ecotone:run', 'consumerName' => 'notifications', '--stopOnFailure' => true, '--executionTimeLimit' => 1000]);
        $application->run($input, $output);

        $this->assertSame(
            1,
            $this->queryBus->sendWithRouting('getNotificationsCount')
        );
    }

    public function test_single_tenant_with_inner_container_using_command_bus(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Customer::class, EcotoneConfiguration::class],
            $this->kernel->getContainer(),
            ServiceConfiguration::createWithDefaults()->withSkippedModulePackageNames(
                ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::SYMFONY_PACKAGE,
                ])
            ),
            addInMemoryStateStoredRepository: false
        );

        $ecotoneLite->sendCommand(new RegisterCustomer(1, 'John Doe'));
        $ecotoneLite->sendCommand(new RegisterCustomer(2, 'John Doe'));

        $this->assertEquals(
            1,
            $ecotoneLite->getAggregate(Customer::class, 1)->getCustomerId()
        );
        $this->assertEquals(
            2,
            $ecotoneLite->getAggregate(Customer::class, 2)->getCustomerId()
        );
    }

    public function test_single_tenant_with_inner_container_using_event_bus(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Customer::class, EcotoneConfiguration::class],
            $this->kernel->getContainer(),
            ServiceConfiguration::createWithDefaults()->withSkippedModulePackageNames(
                ModulePackageList::allPackagesExcept([
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::SYMFONY_PACKAGE,
                ])
            ),
            addInMemoryStateStoredRepository: false
        );

        $ecotoneLite->publishEvent(new ExternalRegistrationHappened(1, 'John Doe'));
        $ecotoneLite->publishEvent(new ExternalRegistrationHappened(2, 'John Doe'));

        $this->assertEquals(
            1,
            $ecotoneLite->getAggregate(Customer::class, 1)->getCustomerId()
        );
        $this->assertEquals(
            2,
            $ecotoneLite->getAggregate(Customer::class, 2)->getCustomerId()
        );
    }
}
