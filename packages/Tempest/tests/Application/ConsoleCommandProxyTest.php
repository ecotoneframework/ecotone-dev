<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Tempest\ConsoleCommandProxyGenerator;
use Ecotone\Tempest\EcotoneConfig;
use Test\Ecotone\Tempest\EcotoneIntegrationTest;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConsoleCommandProxyTest extends EcotoneIntegrationTest
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\'],
            skippedModulePackageNames: ModulePackageList::allPackages(),
            test: true,
        );
    }

    public function test_generator_produces_proxy_files_for_registered_ecotone_commands(): void
    {
        $outputDir = sys_get_temp_dir() . '/ecotone_proxy_test_' . getmypid();

        $generator = new ConsoleCommandProxyGenerator();
        $generatedClasses = $generator->generate(
            [
                \Ecotone\Messaging\Config\ConsoleCommandConfiguration::create(
                    'ecotone.channel.ecotone:list',
                    'ecotone:list',
                    [],
                ),
            ],
            $outputDir,
        );

        $this->assertCount(1, $generatedClasses);
        $this->assertFileExists($generatedClasses[0]);

        $fileContent = file_get_contents($generatedClasses[0]);
        $this->assertStringContainsString("name: 'ecotone:list'", $fileContent);

        array_map('unlink', $generatedClasses);
        @rmdir($outputDir);
    }

    public function test_ecotone_commands_are_registered_in_tempest_console_config(): void
    {
        $consoleConfig = $this->container->get(\Tempest\Console\ConsoleConfig::class);

        $this->assertArrayHasKey('ecotone:list', $consoleConfig->commands);
        $this->assertArrayHasKey('ecotone:run', $consoleConfig->commands);
    }
}
