<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ConsoleCommandConfiguration;
use Ecotone\Messaging\Config\ConsoleCommandParameter;
use Ecotone\Messaging\Console\ConsoleProgressBar;
use Ecotone\Messaging\Console\ConsoleWriter;
use Ecotone\Messaging\Console\DelegatingConsoleWriter;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Ecotone\Tempest\ConsoleCommandProxyGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tempest\Console\Console;
use Tempest\Console\Input\ConsoleArgumentBag;

/**
 * Reproduces: `./tempest ecotone:run notifications` (and the --consumerName=...
 * form) fail with "Missing argument with name consumerName". The generated
 * proxy forwards ConsoleArgumentBag entries by raw name: positional arguments
 * carry a null name (keyed as ''), and Tempest kebab-cases named arguments
 * (consumerName -> consumer-name), so Ecotone's runner never sees the
 * parameter it declared. The package's existing end-to-end test passes only
 * because the console tester bypasses CLI parsing with pre-keyed arrays.
 *
 * licence Apache-2.0
 * @internal
 */
final class ConsoleProxyArgumentMappingTest extends TestCase
{
    public function test_positional_cli_argument_maps_onto_declared_parameter_name(): void
    {
        $captured = $this->runGeneratedProxyWith(['tempest', 'ecotone:hardening', 'notifications']);

        $this->assertSame('notifications', $captured['consumerName'] ?? null, 'Positional CLI argument must map onto the first declared command parameter; got: ' . var_export($captured, true));
        $this->assertArrayNotHasKey('', $captured);
    }

    public function test_kebab_cased_named_cli_argument_maps_onto_declared_camel_case_parameter(): void
    {
        $captured = $this->runGeneratedProxyWith(['tempest', 'ecotone:hardening', '--consumerName=notifications']);

        $this->assertSame('notifications', $captured['consumerName'] ?? null, 'Named CLI argument must survive Tempest kebab-casing; got: ' . var_export($captured, true));
    }

    public function test_boolean_flag_maps_onto_declared_option(): void
    {
        $captured = $this->runGeneratedProxyWith(['tempest', 'ecotone:hardening', 'notifications', '--stopOnFailure']);

        $this->assertTrue($captured['stopOnFailure'] ?? null, 'Flag options must map onto their declared parameter; got: ' . var_export($captured, true));
    }

    private function runGeneratedProxyWith(array $argv): array
    {
        $configuration = ConsoleCommandConfiguration::create(
            'ecotone.hardening.channel',
            'ecotone:hardening',
            [
                ConsoleCommandParameter::create('consumerName', 'header.consumerName', false),
                ConsoleCommandParameter::createWithDefaultValue('stopOnFailure', 'header.stopOnFailure', true, false, false),
            ],
            'hardening reproduction command',
        );

        $outputDirectory = sys_get_temp_dir() . '/ecotone_tempest_hardening_proxies_' . getmypid();
        $generatedFiles = (new ConsoleCommandProxyGenerator())->generate([$configuration], $outputDirectory);
        require_once $generatedFiles[0];

        $capturedParameters = null;
        $runner = new class ($capturedParameters) implements ConsoleCommandRunner {
            public function __construct(private mixed &$captured)
            {
            }

            public function execute($commandName, $parameters): mixed
            {
                $this->captured = $parameters;

                return null;
            }
        };

        $messagingSystem = $this->createStub(ConfiguredMessagingSystem::class);
        $messagingSystem->method('getGatewayByName')->willReturn($runner);
        $messagingSystem->method('getServiceFromContainer')->willReturn(new DelegatingConsoleWriter($this->nullWriter()));

        $proxyClass = new ReflectionClass('Ecotone\Tempest\Generated\EcotoneConsoleCommand_ecotone_hardening');
        $proxy = $proxyClass->newInstanceWithoutConstructor();
        $proxyClass->getProperty('messagingSystem')->setValue($proxy, $messagingSystem);
        $proxyClass->getProperty('argumentBag')->setValue($proxy, new ConsoleArgumentBag($argv));
        $proxyClass->getProperty('console')->setValue($proxy, $this->createStub(Console::class));

        $proxy->__invoke();

        $this->assertIsArray($capturedParameters, 'The proxy must reach ConsoleCommandRunner::execute');

        return $capturedParameters;
    }

    private function nullWriter(): ConsoleWriter
    {
        return new class implements ConsoleWriter {
            public function write(string $message): void
            {
            }

            public function writeln(string $message = ''): void
            {
            }

            public function info(string $message): void
            {
            }

            public function success(string $message): void
            {
            }

            public function warning(string $message): void
            {
            }

            public function error(string $message): void
            {
            }

            public function table(array $columnHeaders, array $rows): void
            {
            }

            public function progressBar(int $maxSteps = 0): ConsoleProgressBar
            {
                throw new \RuntimeException('not needed');
            }
        };
    }
}
