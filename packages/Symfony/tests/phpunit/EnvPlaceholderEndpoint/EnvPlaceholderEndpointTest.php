<?php

declare(strict_types=1);

namespace Test\EnvPlaceholderEndpoint;

use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\CacheClearer;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Symfony\App\EnvPlaceholderEndpoint\Configuration\Kernel;

/**
 * Reproduces https://github.com/ecotoneframework/ecotone-dev/issues/669
 *
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
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
