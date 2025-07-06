<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config;

use Ecotone\Messaging\Config\ConsoleCommandConfiguration;
use Ecotone\Messaging\Config\ConsoleCommandParameter;
use Ecotone\Messaging\Config\PreparedConsoleCommand;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PreparedConsoleCommandTest extends TestCase
{
    public function test_can_create_from_configuration(): void
    {
        $parameter1 = ConsoleCommandParameter::create('param1', 'header1', false, false, null);
        $parameter2 = ConsoleCommandParameter::create('param2', 'header2', true, false, 'default');
        
        $configuration = $this->createMock(ConsoleCommandConfiguration::class);
        $configuration->method('getName')->willReturn('test:command');
        $configuration->method('getParameters')->willReturn([$parameter1, $parameter2]);
        
        $preparedCommand = PreparedConsoleCommand::fromConfiguration($configuration);
        
        $this->assertEquals('test:command', $preparedCommand->getName());
        $this->assertCount(2, $preparedCommand->getParameters());
        $this->assertEquals($parameter1, $preparedCommand->getParameters()[0]);
        $this->assertEquals($parameter2, $preparedCommand->getParameters()[1]);
    }

    public function test_can_create_directly(): void
    {
        $parameter = ConsoleCommandParameter::create('param', 'header', false, false, null);
        
        $preparedCommand = new PreparedConsoleCommand('direct:command', [$parameter]);
        
        $this->assertEquals('direct:command', $preparedCommand->getName());
        $this->assertCount(1, $preparedCommand->getParameters());
        $this->assertEquals($parameter, $preparedCommand->getParameters()[0]);
    }

    public function test_handles_empty_parameters(): void
    {
        $configuration = $this->createMock(ConsoleCommandConfiguration::class);
        $configuration->method('getName')->willReturn('empty:command');
        $configuration->method('getParameters')->willReturn([]);
        
        $preparedCommand = PreparedConsoleCommand::fromConfiguration($configuration);
        
        $this->assertEquals('empty:command', $preparedCommand->getName());
        $this->assertEmpty($preparedCommand->getParameters());
    }
}
