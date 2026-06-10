<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Tempest\EcotoneConfig;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConsoleCommandEndToEndTest extends EcotoneIntegrationTestCase
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\'],
            skippedModulePackageNames: ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]),
            test: true,
        );
    }

    public function test_ecotone_list_shows_registered_async_consumer(): void
    {
        $this->console
            ->call('ecotone:list')
            ->assertSuccess()
            ->assertContains('ecotone_test_queue');
    }

    public function test_ecotone_run_command_runs_async_consumer_through_tempest_console_runner_by_name(): void
    {
        $this->console
            ->call('ecotone:run', ['consumerName' => 'ecotone_test_queue', 'finishWhenNoMessages' => 'true'])
            ->assertSuccess();
    }
}
