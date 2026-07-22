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
