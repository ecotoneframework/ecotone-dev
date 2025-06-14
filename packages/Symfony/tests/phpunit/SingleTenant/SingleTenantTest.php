<?php

declare(strict_types=1);

namespace Test\SingleTenant;

use Ecotone\Dbal\Recoverability\DeadLetterGateway;
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
    private DeadLetterGateway $deadLetterGateway;
    private Kernel $kernel;

    public function setUp(): void
    {
        $kernel = new Kernel('dev', true);
        $kernel->boot();
        $app = $kernel->getContainer();
        runMigrationForSymfony($kernel);

        $this->commandBus = $app->get(CommandBus::class);
        $this->queryBus = $app->get(QueryBus::class);
        $this->deadLetterGateway = $app->get(DeadLetterGateway::class);
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
    public function test_exception_handling_with_error_channel(): void
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $output = new ConsoleOutput();


        // Register a customer that will cause an exception in the async event handler
        $this->commandBus->send(
            new RegisterCustomer(3, 'Error Customer'),
            metadata: ['shouldThrowAsyncExceptionTimes' => 1]
        );

        // Run the notifications consumer which should process the message
        // The first attempt will fail due to the exception
        $input = new ArrayInput([
            'command' => 'ecotone:run',
            'consumerName' => 'notifications',
            '--stopOnFailure' => false,
            '--executionTimeLimit' => 1000,
        ]);
        $application->run($input, $output);

        $this->assertSame(
            0,
            $this->queryBus->sendWithRouting('getNotificationsCount'),
            'Message should be in dead letter, and notifications should not be sent'
        );

        self::assertCount(1, $this->deadLetterGateway->list(100, 0));

        $this->deadLetterGateway->reply(array_values($this->deadLetterGateway->list(100, 0))[0]->getMessageId());

        $this->assertSame(
            0,
            $this->queryBus->sendWithRouting('getNotificationsCount'),
            'Message should be replied to asynchronous message channel first'
        );

        // Process the message again replied to the asynchronous message channel
        $input = new ArrayInput([
            'command' => 'ecotone:run',
            'consumerName' => 'notifications',
            '--stopOnFailure' => false,
            '--executionTimeLimit' => 1000,
        ]);
        $application->run($input, $output);

        $this->assertSame(
            1,
            $this->queryBus->sendWithRouting('getNotificationsCount'),
            'Message should be processed and notifications should be sent'
        );
        self::assertCount(0, $this->deadLetterGateway->list(100, 0));
    }
}
