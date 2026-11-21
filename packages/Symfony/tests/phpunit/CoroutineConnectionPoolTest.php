<?php

declare(strict_types=1);

namespace Test;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\ConnectionPoolExhausted;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Symfony\SymfonyConnectionReference;
use Ecotone\Test\CoroutineTesting;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineConnectionPoolTest extends TestCase
{
    public function test_symfony_zero_pool_wait_refuses_a_second_coroutine_at_once(): void
    {
        $dsn = null;
        foreach ([getenv('DATABASE_DSN'), getenv('SECONDARY_DATABASE_DSN')] as $candidate) {
            if (is_string($candidate) && str_starts_with($candidate, 'mysql')) {
                $dsn = $candidate;
            }
        }
        if ($dsn === null) {
            throw new RuntimeException('The coroutine tests need a mysql database in DATABASE_DSN or SECONDARY_DATABASE_DSN.');
        }
        $connection = (new DbalConnectionFactory($dsn))->establishConnection();
        $doctrine = new class ($connection) {
            public function __construct(private Connection $connection)
            {
            }

            public function getConnection(string $name): Connection
            {
                return $this->connection;
            }
        };
        $handler = new class {
            public Coroutine\Channel $holding;
            public Coroutine\Channel $release;
            public array $handled = [];

            public function __construct()
            {
                $this->holding = new Coroutine\Channel();
                $this->release = new Coroutine\Channel();
            }

            #[CommandHandler('hold')]
            public function hold(#[CurrentConnection] Connection $connection): void
            {
                $connection->fetchOne('SELECT 1');
                $this->holding->push(true);
                $this->release->pop();
                $this->handled[] = 'holder';
            }

            #[CommandHandler('probe')]
            public function probe(#[CurrentConnection] Connection $connection): void
            {
                $this->handled[] = 'probe';
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, 'doctrine' => $doctrine],
            extensionObjects: [SymfonyConnectionReference::defaultConnection('default')->withConnectionPoolSize(1)->withConnectionPoolWaitSeconds(0), DbalConfiguration::createWithDefaults(), InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false)],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::SYMFONY_PACKAGE],
        );
        $failure = null;

        CoroutineTesting::runAtOnce(
            static fn () => $ecotone->sendCommandWithRouting('hold'),
            static function () use ($handler, $ecotone, &$failure): void {
                $handler->holding->pop();
                try {
                    $ecotone->sendCommandWithRouting('probe');
                } catch (ConnectionPoolExhausted $exception) {
                    $failure = $exception;
                } finally {
                    $handler->release->push(true);
                }
            },
        );

        $this->assertInstanceOf(ConnectionPoolExhausted::class, $failure);
        $this->assertStringContainsString('and the pool is set not to wait for one (a wait of 0 seconds)', $failure->getMessage());
        $this->assertStringContainsString('lower CoroutineMessageChannelBuilder::withCoroutines()', $failure->getMessage());
        $this->assertSame(['holder'], $handler->handled);
    }
}
