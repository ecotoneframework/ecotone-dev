<?php

declare(strict_types=1);

namespace Test;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConsoleWriterCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel([
            'environment' => 'test',
        ]);
    }

    protected function tearDown(): void
    {
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_console_command_writes_formatted_output_through_symfony_console(): void
    {
        $commandTester = $this->prepareCommandTester();

        $commandTester->execute(['name' => 'orders']);

        $commandTester->assertCommandIsSuccessful();
        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('Starting orders', $display);
        $this->assertStringContainsString('Processed orders', $display);
        $this->assertStringContainsString('Almost done orders', $display);
        $this->assertStringContainsString('Failed orders', $display);
        $this->assertStringContainsString('running', $display);
        $this->assertStringContainsString('2/2', $display);
    }

    public function test_console_command_writes_colored_output_when_decorated(): void
    {
        $commandTester = $this->prepareCommandTester();

        $commandTester->execute(['name' => 'orders'], ['decorated' => true]);

        $display = $commandTester->getDisplay();
        $this->assertStringContainsString("\033[36mStarting orders\033[39m", $display);
        $this->assertStringContainsString("\033[32mProcessed orders\033[39m", $display);
        $this->assertStringContainsString("\033[33mAlmost done orders\033[39m", $display);
        $this->assertStringContainsString("\033[31mFailed orders\033[39m", $display);
    }

    public function test_console_command_description_is_visible_in_symfony_console(): void
    {
        $application = new Application(self::$kernel);

        $this->assertSame(
            'Shows formatted console writer output',
            $application->find('console-writer:show')->getDescription()
        );
    }

    public function test_built_in_ecotone_commands_have_descriptions(): void
    {
        $application = new Application(self::$kernel);

        $this->assertSame(
            'Lists all registered asynchronous message consumers',
            $application->find('ecotone:list')->getDescription()
        );
        $this->assertSame(
            'Runs an asynchronous message consumer by name',
            $application->find('ecotone:run')->getDescription()
        );
    }

    private function prepareCommandTester(): CommandTester
    {
        $application = new Application(self::$kernel);
        $command = $application->find('console-writer:show');

        return new CommandTester($command);
    }
}
