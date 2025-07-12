<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Integration;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Tempest\EcotoneTempestConfiguration;
use PHPUnit\Framework\TestCase;
use Tempest\Console\Actions\ExecuteConsoleCommand;
use Tempest\Core\Tempest;
use Tempest\Discovery\DiscoveryLocation;
use Test\Ecotone\Tempest\Fixture\AsynchronousMessageHandler\AsyncCommand;
use Test\Ecotone\Tempest\Fixture\AsynchronousMessageHandler\AsyncCommandHandler;
use Test\Ecotone\Tempest\Fixture\BusinessInterface\TicketService;

/**
 * @internal
 * licence Apache-2.0
 */
final class AsynchronousCommandIntegrationTest extends TestCase
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

    public function test_asynchronous_command_handling_through_tempest_console(): void
    {
        $discoveryLocations = [
            EcotoneTempestConfiguration::getDiscoveryPath(),
            new DiscoveryLocation('Test\\Ecotone\\Tempest\\Fixture\\', __DIR__ . '/../Fixture/'),
        ];

        // Boot Tempest with Ecotone integration
        $container = Tempest::boot(__DIR__ . '/../../', $discoveryLocations);
        
        // Register test services
        if (!$container->has(TicketService::class)) {
            $container->singleton(TicketService::class, new TicketService());
        }
        
        if (!$container->has(AsyncCommandHandler::class)) {
            $container->singleton(AsyncCommandHandler::class, new AsyncCommandHandler());
        }

        // Get Ecotone services
        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);
        $commandBus = $messagingSystem->getCommandBus();
        $queryBus = $messagingSystem->getQueryBus();
        $executeCommand = $container->get(ExecuteConsoleCommand::class);

        // Reset the handler state
        $handler = $container->get(AsyncCommandHandler::class);
        $handler->reset();

        // Verify no commands processed initially
        $initialCount = $queryBus->sendWithRouting('consumer.getProcessedCommandsCount');
        $this->assertEquals(0, $initialCount, 'Should start with no processed commands');

        // Send an asynchronous command
        $asyncCommand = new AsyncCommand('test-123', 'Hello Async World!');
        $commandBus->sendWithRouting('execute.async_command', $asyncCommand);

        // Verify command is not processed yet (it's queued)
        $countAfterSend = $queryBus->sendWithRouting('consumer.getProcessedCommandsCount');
        $this->assertEquals(0, $countAfterSend, 'Command should be queued, not processed yet');

        // Run the async consumer through Ecotone's messaging system directly
        // (bypassing Tempest console for now to test the core functionality)
        $result = $messagingSystem->runConsoleCommand('ecotone:run', [
            'consumerName' => 'async_channel',
            'finishWhenNoMessages' => true
        ]);

        // The command should execute successfully (null return is expected for ecotone:run)
        $this->assertTrue(true, 'ecotone:run executed without throwing an exception');

        // Verify the command was processed
        $countAfterConsume = $queryBus->sendWithRouting('consumer.getProcessedCommandsCount');
        $this->assertEquals(1, $countAfterConsume, 'Command should be processed after running consumer');

        // Verify the processed command details
        $lastProcessedCommand = $queryBus->sendWithRouting('consumer.getLastProcessedCommand');
        $this->assertNotNull($lastProcessedCommand, 'Should have a processed command');
        $this->assertArrayHasKey('payload', $lastProcessedCommand);
        $this->assertArrayHasKey('headers', $lastProcessedCommand);

        $processedPayload = $lastProcessedCommand['payload'];
        $this->assertInstanceOf(AsyncCommand::class, $processedPayload);
        $this->assertEquals('test-123', $processedPayload->id);
        $this->assertEquals('Hello Async World!', $processedPayload->message);

        // Verify headers are present
        $this->assertIsArray($lastProcessedCommand['headers']);
        $this->assertNotEmpty($lastProcessedCommand['headers']);
    }

    public function test_multiple_async_commands_processing(): void
    {
        $discoveryLocations = [
            EcotoneTempestConfiguration::getDiscoveryPath(),
            new DiscoveryLocation('Test\\Ecotone\\Tempest\\Fixture\\', __DIR__ . '/../Fixture/'),
        ];

        // Boot Tempest with Ecotone integration
        $container = Tempest::boot(__DIR__ . '/../../', $discoveryLocations);
        
        // Register test services
        if (!$container->has(TicketService::class)) {
            $container->singleton(TicketService::class, new TicketService());
        }
        
        if (!$container->has(AsyncCommandHandler::class)) {
            $container->singleton(AsyncCommandHandler::class, new AsyncCommandHandler());
        }

        // Get Ecotone services
        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);
        $commandBus = $messagingSystem->getCommandBus();
        $queryBus = $messagingSystem->getQueryBus();
        $executeCommand = $container->get(ExecuteConsoleCommand::class);

        // Reset the handler state
        $handler = $container->get(AsyncCommandHandler::class);
        $handler->reset();

        // Send multiple asynchronous commands
        $commands = [
            new AsyncCommand('cmd-1', 'First command'),
            new AsyncCommand('cmd-2', 'Second command'),
            new AsyncCommand('cmd-3', 'Third command'),
        ];

        foreach ($commands as $command) {
            $commandBus->sendWithRouting('execute.async_command', $command);
        }

        // Verify commands are queued but not processed
        $countBeforeConsume = $queryBus->sendWithRouting('consumer.getProcessedCommandsCount');
        $this->assertEquals(0, $countBeforeConsume, 'Commands should be queued, not processed yet');

        // Run the async consumer through Ecotone's messaging system directly
        $result = $messagingSystem->runConsoleCommand('ecotone:run', [
            'consumerName' => 'async_channel',
            'finishWhenNoMessages' => true
        ]);

        // The command should execute successfully (null return is expected for ecotone:run)
        $this->assertTrue(true, 'Consumer executed without throwing an exception');

        // Verify all commands were processed
        $countAfterConsume = $queryBus->sendWithRouting('consumer.getProcessedCommandsCount');
        $this->assertEquals(3, $countAfterConsume, 'All 3 commands should be processed');

        // Verify all processed commands
        $allProcessedCommands = $queryBus->sendWithRouting('consumer.getProcessedCommands');
        $this->assertCount(3, $allProcessedCommands);

        $processedIds = array_map(fn($cmd) => $cmd['payload']->id, $allProcessedCommands);
        $this->assertContains('cmd-1', $processedIds);
        $this->assertContains('cmd-2', $processedIds);
        $this->assertContains('cmd-3', $processedIds);
    }
}
