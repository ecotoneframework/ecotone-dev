<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\HeadersInLogContext;

use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Laravel\EcotoneProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Http\Kernel;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * licence Apache-2.0
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class HeadersInLogContextTest extends TestCase
{
    public function test_headers_listed_in_config_ecotone_are_in_the_log_context(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var array<int, array{message: string, context: array<string, mixed>}> */
            private array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }

            /** @return array<string, mixed> */
            public function contextOf(string $messageFragment): array
            {
                foreach ($this->records as $record) {
                    if (str_contains($record['message'], $messageFragment)) {
                        return $record['context'];
                    }
                }

                throw new RuntimeException("No log record containing `{$messageFragment}`");
            }
        };
        $app = self::bootWith(
            [
                'loadAppNamespaces' => false,
                'namespaces' => ['Test\\Ecotone\\Laravel\\Fixture\\ExpressionLanguage'],
                'enabledModulePackageNames' => [],
                'headersInLogContext' => ['userId'],
            ],
            $logger,
        );

        $app->get(CommandBus::class)->sendWithRouting('setAmount', ['amount' => 100], metadata: ['userId' => 'user-7', 'region' => 'eu']);

        $context = $logger->contextOf('Executing Command Handler');
        self::assertSame('user-7', $context['userId']);
        self::assertArrayNotHasKey('region', $context);
    }

    public function test_the_shipped_configuration_names_the_key(): void
    {
        $shippedConfiguration = require __DIR__ . '/../../config/ecotone.php';

        self::assertSame([], $shippedConfiguration['headersInLogContext']);
    }

    private static function bootWith(array $ecotoneConfiguration, object $logger): Application
    {
        $app = require __DIR__ . '/../EnabledModulePackageNames/bootstrap/app.php';
        $app->afterBootstrapping(
            LoadConfiguration::class,
            function (Application $app) use ($ecotoneConfiguration, $logger): void {
                $app['config']->set('ecotone', $ecotoneConfiguration);
                $app->instance('logger', $logger);
            },
        );
        $app->make(Kernel::class)->bootstrap();
        EcotoneCacheClear::clearEcotoneCacheDirectories(EcotoneProvider::getCacheDirectoryPath());

        return $app;
    }
}
