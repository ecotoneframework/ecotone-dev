<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ConsoleCommandConfiguration;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
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
                ConsoleCommandConfiguration::create(
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

    public function test_generated_proxy_invoke_forwards_to_ecotone_console_command_runner_and_returns_exit_success(): void
    {
        $proxyClassName = 'Ecotone\\Tempest\\Generated\\EcotoneConsoleCommand_ecotone_list';

        $this->assertTrue(class_exists($proxyClassName));

        $proxy = $this->container->get($proxyClassName);

        $result = $proxy->__invoke();

        $this->assertSame(\Tempest\Console\ExitCode::SUCCESS, $result);
    }

    public function test_proxy_files_are_not_rewritten_when_config_hash_is_unchanged(): void
    {
        $outputDir = sys_get_temp_dir() . '/ecotone_proxy_hash_test_' . getmypid();
        $commands = [
            ConsoleCommandConfiguration::create('ecotone.channel.ecotone:list', 'ecotone:list', []),
        ];
        $configHash = 'abc123';

        $generator = new ConsoleCommandProxyGenerator();
        $firstFiles = $generator->generate($commands, $outputDir, $configHash);

        $mtimeBefore = filemtime($firstFiles[0]);

        sleep(1);

        $secondFiles = $generator->generate($commands, $outputDir, $configHash);

        $mtimeAfter = filemtime($secondFiles[0]);

        $this->assertSame($mtimeBefore, $mtimeAfter);

        foreach ($firstFiles as $file) {
            @unlink($file);
        }
        @unlink($outputDir . '/.ecotone_hash');
        @rmdir($outputDir);
    }

    public function test_proxy_files_are_rewritten_when_config_hash_changes(): void
    {
        $outputDir = sys_get_temp_dir() . '/ecotone_proxy_rehash_test_' . getmypid();
        $commands = [
            ConsoleCommandConfiguration::create('ecotone.channel.ecotone:list', 'ecotone:list', []),
        ];

        $generator = new ConsoleCommandProxyGenerator();
        $firstFiles = $generator->generate($commands, $outputDir, 'hash_v1');

        $mtimeBefore = filemtime($firstFiles[0]);

        sleep(1);

        $secondFiles = $generator->generate($commands, $outputDir, 'hash_v2');

        $mtimeAfter = filemtime($secondFiles[0]);

        $this->assertGreaterThan($mtimeBefore, $mtimeAfter);

        foreach ($secondFiles as $file) {
            @unlink($file);
        }
        @unlink($outputDir . '/.ecotone_hash');
        @rmdir($outputDir);
    }
}
