<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening;

use Ecotone\Tempest\TempestPsrContainerAdapter;
use PHPUnit\Framework\TestCase;
use Tempest\Container\Container;
use Tempest\Container\GenericContainer;
use Tempest\Container\Initializer;

/**
 * Reproduces: Ecotone handler parameters referencing Tempest services that are
 * provided by an Initializer (e.g. Tempest\Mail\Mailer) fail to wire under the
 * compiled container, because the adapter's has() does not account for
 * initializer-resolvable services. ExternalReferenceResolver then throws
 * "Reference ... was not found in definitions" while building the channel.
 *
 * licence Apache-2.0
 * @internal
 */
final class TempestPsrContainerAdapterInitializerServicesTest extends TestCase
{
    public function test_has_reports_interface_services_resolvable_through_tempest_initializers(): void
    {
        $container = new GenericContainer();
        $container->addInitializer(ServiceBehindInitializerInitializer::class);

        $adapter = new TempestPsrContainerAdapter($container);

        $this->assertTrue(
            $adapter->has(ServiceBehindInitializer::class),
            'Interface services provided by Tempest initializers must be reported as available - Ecotone references them when wiring handler parameters',
        );
        $this->assertInstanceOf(ServiceBehindInitializer::class, $adapter->get(ServiceBehindInitializer::class));
    }

    public function test_has_still_rejects_services_nobody_can_provide(): void
    {
        $container = new GenericContainer();

        $adapter = new TempestPsrContainerAdapter($container);

        $this->assertFalse($adapter->has(ServiceBehindInitializer::class));
    }

    public function test_has_answers_from_the_initializer_registry_without_constructing_the_service(): void
    {
        ConnectionOpeningInitializer::$opened = false;
        $container = new GenericContainer();
        $container->addInitializer(ConnectionOpeningInitializer::class);

        $adapter = new TempestPsrContainerAdapter($container);

        $this->assertTrue($adapter->has(ExpensiveConnection::class));
        $this->assertFalse(
            ConnectionOpeningInitializer::$opened,
            'has() is a capability question — it must never construct the service (imagine a database connection opened during compilation)',
        );

        $this->assertInstanceOf(ExpensiveConnection::class, $adapter->get(ExpensiveConnection::class));
        $this->assertTrue(ConnectionOpeningInitializer::$opened, 'get() is the one that constructs');
    }

    public function test_has_reports_instantiable_class_without_constructing_it(): void
    {
        ConstructionRecordingService::$constructed = false;
        $container = new GenericContainer();

        $adapter = new TempestPsrContainerAdapter($container);

        $this->assertTrue(
            $adapter->has(ConstructionRecordingService::class),
            'Tempest autowires concrete classes on demand — they are available without being registered',
        );
        $this->assertFalse(
            ConstructionRecordingService::$constructed,
            'has() must not autowire the class to prove it can be built — construction belongs to dispatch time',
        );
    }
}

final class ExpensiveConnection
{
}

final class ConnectionOpeningInitializer implements Initializer
{
    public static bool $opened = false;

    public function initialize(Container $container): ExpensiveConnection
    {
        self::$opened = true;

        return new ExpensiveConnection();
    }
}

final class ConstructionRecordingService
{
    public static bool $constructed = false;

    public function __construct()
    {
        self::$constructed = true;
    }
}

interface ServiceBehindInitializer
{
}

final class ServiceBehindInitializerImplementation implements ServiceBehindInitializer
{
}

final class ServiceBehindInitializerInitializer implements Initializer
{
    public function initialize(Container $container): ServiceBehindInitializer
    {
        return new ServiceBehindInitializerImplementation();
    }
}
