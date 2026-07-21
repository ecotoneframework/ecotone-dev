<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Application\Execution;

use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Laravel\EcotoneProvider;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConsoleWriterCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('SHELL_VERBOSITY=0');
        $_ENV['SHELL_VERBOSITY'] = 0;
        $_SERVER['SHELL_VERBOSITY'] = 0;
    }

    protected function tearDown(): void
    {
        putenv('SHELL_VERBOSITY=-1');
        $_ENV['SHELL_VERBOSITY'] = -1;
        $_SERVER['SHELL_VERBOSITY'] = -1;

        parent::tearDown();
    }

    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        EcotoneCacheClear::clearEcotoneCacheDirectories(EcotoneProvider::getCacheDirectoryPath());

        return $app;
    }

    public function test_console_command_writes_formatted_output_through_artisan(): void
    {
        $this->withoutMockingConsoleOutput();

        Artisan::call('console-writer:show', ['name' => 'orders']);

        $output = Artisan::output();
        $this->assertStringContainsString('Starting orders', $output);
        $this->assertStringContainsString('Processed orders', $output);
        $this->assertStringContainsString('Almost done orders', $output);
        $this->assertStringContainsString('Failed orders', $output);
        $this->assertStringContainsString('running', $output);
        $this->assertStringContainsString('2/2', $output);
    }

    public function test_console_command_description_is_visible_in_artisan(): void
    {
        $this->assertSame(
            'Shows formatted console writer output',
            Artisan::all()['console-writer:show']->getDescription()
        );
    }

    public function test_console_command_writes_colored_output_when_decorated(): void
    {
        $this->withoutMockingConsoleOutput();

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

        Artisan::call('console-writer:show', ['name' => 'orders'], $output);

        $content = $output->fetch();
        $this->assertStringContainsString("\033[36mStarting orders\033[39m", $content);
        $this->assertStringContainsString("\033[32mProcessed orders\033[39m", $content);
        $this->assertStringContainsString("\033[33mAlmost done orders\033[39m", $content);
        $this->assertStringContainsString("\033[31mFailed orders\033[39m", $content);
    }
}
