<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Console\ConsoleCommandResultSet;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use php_user_filter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class EcotoneLiteConsoleTest extends TestCase
{
    private string $display = '';

    public function test_listing_console_commands_with_their_descriptions(): void
    {
        $commands = new class () {
            #[ConsoleCommand('app:orders:ship', 'Ships the given order')]
            public function ship(string $orderId): void
            {
            }
        };

        $ecotone = EcotoneLite::bootstrap(
            classesToResolve: [$commands::class],
            containerOrAvailableServices: [$commands],
            enabledModules: [],
        );

        self::assertSame(0, $this->runConsole($ecotone, 'list'));
        self::assertMatchesRegularExpression('/app:orders:ship\s+Ships the given order/', $this->display);
        self::assertMatchesRegularExpression('/ecotone:run\s+Runs an asynchronous message consumer by name/', $this->display);
        self::assertMatchesRegularExpression('/ecotone:list\s+Lists all registered asynchronous message consumers/', $this->display);
    }

    public function test_every_listed_console_command_has_a_description(): void
    {
        $application = new class () {
        };
        $ecotone = EcotoneLite::bootstrap(classesToResolve: [$application::class], enabledModules: []);

        self::assertSame(0, $this->runConsole($ecotone, 'list', '--format=json'));

        $commandsWithoutDescription = [];
        foreach (json_decode($this->display, true, flags: JSON_THROW_ON_ERROR)['commands'] as $command) {
            if ($command['description'] === '') {
                $commandsWithoutDescription[] = $command['name'];
            }
        }

        self::assertSame([], $commandsWithoutDescription);
    }

    public function test_running_a_console_command_with_arguments_and_options(): void
    {
        $commands = new class () {
            public array $shipped = [];

            #[ConsoleCommand('app:orders:ship', 'Ships the given order')]
            public function ship(string $orderId, #[ConsoleParameterOption] string $carrier = 'post', #[ConsoleParameterOption] bool $express = false, array $tags = []): void
            {
                $this->shipped[] = [$orderId, $carrier, $express, $tags];
            }
        };

        $ecotone = EcotoneLite::bootstrap(
            classesToResolve: [$commands::class],
            containerOrAvailableServices: [$commands],
            enabledModules: [],
        );

        self::assertSame(0, $this->runConsole($ecotone, 'app:orders:ship', '123', '--carrier=courier', '--express', '--tags=fragile', '--tags=gift'));
        self::assertSame(0, $this->runConsole($ecotone, 'app:orders:ship', '124'));

        self::assertSame([['123', 'courier', true, ['fragile', 'gift']], ['124', 'post', false, []]], $commands->shipped);
    }

    public function test_running_an_asynchronous_consumer_from_the_console(): void
    {
        $handler = new class () {
            public array $placed = [];

            #[Asynchronous('async')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order): void
            {
                $this->placed[] = $order;
            }
        };
        $ecotone = EcotoneLite::bootstrap(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('async')],
            enabledModules: [],
        );
        $ecotone->getCommandBus()->sendWithRouting('order.place', 'coffee');

        self::assertSame([], $handler->placed);
        self::assertSame(0, $this->runConsole($ecotone, 'ecotone:run', 'async', '--finishWhenNoMessages'));
        self::assertSame(['coffee'], $handler->placed);

        self::assertSame(0, $this->runConsole($ecotone, 'ecotone:list'));
        self::assertStringContainsString('async', $this->display);
    }

    public function test_console_command_output_is_printed_to_the_console(): void
    {
        $commands = new class () {
            #[ConsoleCommand('app:orders:list', 'Lists the placed orders')]
            public function list(ConsoleWriter $console): ConsoleCommandResultSet
            {
                $console->writeln('Listing orders');

                return ConsoleCommandResultSet::create(['Order', 'Status'], [['coffee', 'placed']]);
            }
        };

        $ecotone = EcotoneLite::bootstrap(
            classesToResolve: [$commands::class],
            containerOrAvailableServices: [$commands],
            enabledModules: [],
        );

        self::assertSame(0, $this->runConsole($ecotone, 'app:orders:list'));
        self::assertStringContainsString('Listing orders', $this->display);
        self::assertMatchesRegularExpression('/Order\s+\|\s+Status/', $this->display);
        self::assertMatchesRegularExpression('/coffee\s+\|\s+placed/', $this->display);
    }

    public function test_a_failing_console_command_ends_with_a_failure_exit_code(): void
    {
        $commands = new class () {
            #[ConsoleCommand('app:orders:ship', 'Ships the given order')]
            public function ship(): void
            {
                throw new RuntimeException('Carrier is not available');
            }
        };

        $ecotone = EcotoneLite::bootstrap(
            classesToResolve: [$commands::class],
            containerOrAvailableServices: [$commands],
            enabledModules: [],
        );

        self::assertNotSame(0, $this->runConsole($ecotone, 'app:orders:ship'));
        self::assertStringContainsString('Carrier is not available', $this->display);
    }

    public function test_running_a_console_command_of_a_flow_tested_application(): void
    {
        $commands = new class () {
            public array $shipped = [];

            #[ConsoleCommand('app:orders:ship', 'Ships the given order')]
            public function ship(string $orderId): void
            {
                $this->shipped[] = $orderId;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$commands::class],
            containerOrAvailableServices: [$commands],
            enabledModules: [],
        );

        self::assertSame(0, $this->runConsole($ecotone, 'app:orders:ship', '123'));
        self::assertSame(['123'], $commands->shipped);
    }

    private function runConsole(ConfiguredMessagingSystem|FlowTestSupport $ecotone, string ...$arguments): int
    {
        $capturedOutput = new class () extends php_user_filter {
            public static string $captured = '';

            public function filter($in, $out, &$consumed, bool $closing): int
            {
                while ($bucket = stream_bucket_make_writeable($in)) {
                    self::$captured .= $bucket->data;
                    $consumed += $bucket->datalen;
                }

                return PSFS_FEED_ME;
            }
        };
        $capturedOutput::$captured = '';
        stream_filter_register('ecotone.console.captured', $capturedOutput::class);
        $standardOutput = stream_filter_append(STDOUT, 'ecotone.console.captured', STREAM_FILTER_WRITE);
        $errorOutput = stream_filter_append(STDERR, 'ecotone.console.captured', STREAM_FILTER_WRITE);
        $scriptArguments = $_SERVER['argv'];
        $_SERVER['argv'] = ['ecotone.php', ...$arguments, '--no-ansi'];

        try {
            return $ecotone->console();
        } finally {
            $_SERVER['argv'] = $scriptArguments;
            stream_filter_remove($standardOutput);
            stream_filter_remove($errorOutput);
            $this->display = $capturedOutput::$captured;
        }
    }
}
