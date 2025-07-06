<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Integration;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Tempest\EcotoneTempestConfiguration;
use PHPUnit\Framework\TestCase;
use Tempest\Console\Actions\ExecuteConsoleCommand;
use Tempest\Console\ConsoleConfig;
use Tempest\Console\ExitCode;
use Tempest\Core\Tempest;
use Tempest\Discovery\DiscoveryLocation;
use Test\Ecotone\Tempest\Fixture\BusinessInterface\TicketService;

/**
 * @internal
 * licence Apache-2.0
 */
final class ConsoleCommandIntegrationTest extends TestCase
{
    private string $originalCwd;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd();
        chdir(__DIR__ . '/../../');
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
    }



    public function test_ecotone_console_commands_integration_end_to_end(): void
    {
        $discoveryLocations = [
            EcotoneTempestConfiguration::getDiscoveryPath(),
            new DiscoveryLocation('Test\\Ecotone\\Tempest\\Fixture\\', __DIR__ . '/../Fixture/'),
        ];

        // Boot Tempest with Ecotone integration
        $container = Tempest::boot(__DIR__ . '/../../', $discoveryLocations);

        // Manually register services if needed
        if (!$container->has(TicketService::class)) {
            $container->singleton(TicketService::class, new TicketService());
        }

        // Verify Ecotone messaging system is available
        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);
        $this->assertInstanceOf(ConfiguredMessagingSystem::class, $messagingSystem);

        // Verify Ecotone has console commands
        $ecotoneCommands = $messagingSystem->getRegisteredConsoleCommands();
        $this->assertNotEmpty($ecotoneCommands, 'Ecotone should have console commands');

        $commandNames = array_map(fn($cmd) => $cmd->getName(), $ecotoneCommands);
        $this->assertContains('ecotone:list', $commandNames, 'ecotone:list should be available');
        $this->assertContains('ecotone:run', $commandNames, 'ecotone:run should be available');



        // Verify commands are registered with Tempest's console system
        $consoleConfig = $container->get(ConsoleConfig::class);
        $registeredCommands = array_keys($consoleConfig->commands);

        $this->assertContains('ecotone:list', $registeredCommands, 'ecotone:list should be registered with Tempest');
        $this->assertContains('ecotone:run', $registeredCommands, 'ecotone:run should be registered with Tempest');

        // Get the specific command to verify it's properly configured
        $ecotoneListCommand = $consoleConfig->commands['ecotone:list'];
        $this->assertEquals('ecotone:list', $ecotoneListCommand->getName());
        $this->assertEquals('EcotoneCommandEcotoneList', $ecotoneListCommand->handler->getDeclaringClass()->getName());
        $this->assertEquals('execute', $ecotoneListCommand->handler->getName());

        // Test execution through Tempest's console system
        $executeCommand = $container->get(ExecuteConsoleCommand::class);

        // Test that we can execute ecotone:list command through Tempest's console system
        $exitCode = $executeCommand('ecotone:list', []);

        // The command should execute successfully
        $this->assertTrue(
            $exitCode === 0 || $exitCode === ExitCode::SUCCESS,
            'ecotone:list should execute successfully through Tempest console system'
        );
    }


}
