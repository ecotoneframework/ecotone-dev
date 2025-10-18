<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Modelling\Config\InstantRetry\InstantRetryConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\InstantRetry\Aggregate\Customer;
use Test\Ecotone\EventSourcing\Fixture\InstantRetry\AggregateMessages\CustomerRegistered;
use Test\Ecotone\EventSourcing\Fixture\InstantRetry\AggregateMessages\RegisterCustomer;
use Test\Ecotone\EventSourcing\Fixture\InstantRetry\ConnectionClosingInterceptor;
use Test\Ecotone\EventSourcing\Fixture\InstantRetry\EventsConverter;
use Test\Ecotone\EventSourcing\Fixture\InstantRetry\Nested\CreateCustomerCaller;
use Test\Ecotone\EventSourcing\Fixture\InstantRetry\TestRetryLogger;

/**
 * @internal
 */
final class InstantRetryTransactionInteractionTest extends EventSourcingMessagingTestCase
{
    public function test_retry_happens_inside_aborted_transaction_with_prooph_conflict(): void
    {
        $logger = new TestRetryLogger();
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Customer::class, RegisterCustomer::class, CustomerRegistered::class, EventsConverter::class],
            containerOrAvailableServices: [
                DbalConnectionFactory::class => self::getConnectionFactory(),
                new EventsConverter(),
                'logger' => $logger,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::CORE_PACKAGE,
                ]))
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    \Ecotone\Dbal\Configuration\DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
                    InstantRetryConfiguration::createWithDefaults()
                        ->withCommandBusRetry(isEnabled: true, retryTimes: 1, retryExceptions: [ConcurrencyException::class]),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $id = 'cust-1';

        // Seed the aggregate via factory command
        $ecotone->sendCommand(new RegisterCustomer($id));

        // Trigger a conflicting append within transactional interceptor + instant retry
        try {
            $ecotone->sendCommand(new RegisterCustomer($id));
            self::fail('Expected ConcurrencyException to be thrown');
        } catch (ConcurrencyException $e) {
            // ok
        }

        // Verify that retry has happened (logged by InstantRetryInterceptor)
        self::assertTrue(
            $logger->containsInfoSubstring('Trying to self-heal by doing instant try'),
            'Expected InstantRetry to log a retry attempt'
        );
    }

    public function test_retry_with_nested_command_handlers_on_factory_conflict(): void
    {
        $logger = new TestRetryLogger();
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [Customer::class, RegisterCustomer::class, CustomerRegistered::class, EventsConverter::class, CreateCustomerCaller::class],
            containerOrAvailableServices: [
                DbalConnectionFactory::class => self::getConnectionFactory(),
                new EventsConverter(),
                new CreateCustomerCaller(),
                'logger' => $logger,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::CORE_PACKAGE,
                ]))
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    \Ecotone\Dbal\Configuration\DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
                    InstantRetryConfiguration::createWithDefaults()
                        ->withCommandBusRetry(isEnabled: true, retryTimes: 1, retryExceptions: [ConcurrencyException::class]),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $id = 'cust-nested-1';
        $ecotone->sendCommand(new RegisterCustomer($id));

        try {
            $ecotone->sendCommandWithRoutingKey('customer.create.via.caller', new RegisterCustomer($id));
            self::fail('Expected ConcurrencyException to be thrown');
        } catch (ConcurrencyException $e) {
            // ok
        }

        self::assertTrue(
            $logger->containsInfoSubstring('Trying to self-heal by doing instant try'),
            'Expected InstantRetry to log a retry attempt'
        );
    }


    public function test_reconnects_after_connection_closed_between_retry_attempts(): void
    {
        if (! str_contains(getenv('DATABASE_DSN'), 'pgsql')) {
            self::markTestSkipped('Only supported on PostgreSQL, because of implicit commits in mysql');

            return;
        }

        $logger = new TestRetryLogger();
        $connectionFactory = self::getConnectionFactory();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                Customer::class,
                RegisterCustomer::class,
                CustomerRegistered::class,
                EventsConverter::class,
                CreateCustomerCaller::class,
                ConnectionClosingInterceptor::class,
            ],
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $connectionFactory,
                new EventsConverter(),
                new CreateCustomerCaller(),
                new ConnectionClosingInterceptor([true, false]),
                'logger' => $logger,
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([
                    ModulePackageList::EVENT_SOURCING_PACKAGE,
                    ModulePackageList::DBAL_PACKAGE,
                    ModulePackageList::CORE_PACKAGE,
                ]))
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults(),
                    \Ecotone\Dbal\Configuration\DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
                    InstantRetryConfiguration::createWithDefaults()
                        ->withCommandBusRetry(isEnabled: true, retryTimes: 1, retryExceptions: [ConcurrencyException::class, NoActiveTransaction::class, ConnectionException::class]),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $id = 'cust-reconnect-1';
        $ecotone->sendCommand(new RegisterCustomer($id));

        // Verify InstantRetry performed a retry (implying the second attempt executed after connection close)
        self::assertTrue(
            $logger->containsInfoSubstring('Trying to self-heal by doing instant try'),
            'Expected InstantRetry to log a retry attempt after closing the connection'
        );

        self::assertNotNull(
            $ecotone->getAggregate(Customer::class, $id),
            'Expected aggregate to be created after retry'
        );
    }
}
