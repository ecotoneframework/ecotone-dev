<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Application;

use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Tempest\EcotoneConfig;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class HeadersInLogContextTest extends EcotoneIntegrationTestCase
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\ExpressionLanguage\\'],
            enabledModulePackageNames: [],
            test: true,
            headersInLogContext: ['userId'],
        );
    }

    public function test_headers_listed_in_ecotone_config_are_in_the_log_context(): void
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
        $this->container->singleton(LoggerInterface::class, $logger);

        $this->container->get(CommandBus::class)->sendWithRouting('setAmount', ['amount' => 100], metadata: ['userId' => 'user-7', 'region' => 'eu']);

        $context = $logger->contextOf('Executing Command Handler');
        self::assertSame('user-7', $context['userId']);
        self::assertArrayNotHasKey('region', $context);
    }
}
