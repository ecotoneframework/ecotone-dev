<?php

declare(strict_types=1);

namespace Test\EnvPlaceholderEndpoint;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\CommandBus;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\CacheClearer;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Symfony\App\EnvPlaceholderEndpoint\Configuration\Kernel;

/**
 * Reproduces https://github.com/ecotoneframework/ecotone-dev/issues/669
 *
 * licence Enterprise
 * @internal
 */
final class EnvPlaceholderEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('SYMFONY_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);
        putenv('ECOTONE_ERROR_CHANNEL=orders.error');
    }

    protected function tearDown(): void
    {
        putenv('SYMFONY_LICENCE_KEY');
        putenv('ECOTONE_ERROR_CHANNEL');

        restore_exception_handler();
    }

    public function test_consuming_async_handler_whose_endpoint_annotation_uses_env_placeholder(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $container = $kernel->getContainer();
        $container->get(CacheClearer::class)->clear('');

        $container->get(CommandBus::class)->sendWithRouting('order.place', 'order-1');

        $container->get(ConfiguredMessagingSystem::class)
            ->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->expectNotToPerformAssertions();
    }
}
