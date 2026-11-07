<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use App\Infrastructure\EcotoneConfiguration;
use App\Ping\DemoPing;
use App\Ping\PongLog;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class EcotoneConfigurationTest extends TestCase
{
    private array $channels;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection((new DsnParser(['pgsql' => 'pdo_pgsql']))->parse(getenv('DATABASE_DSN')));
        $connection->executeStatement('DELETE FROM demo_pongs');
        $connection->close();
        $this->channels = [
            SimpleMessageChannelBuilder::createQueueChannel('storefront'),
            SimpleMessageChannelBuilder::createQueueChannel('ticketing'),
            SimpleMessageChannelBuilder::createQueueChannel('storefront_async'),
            SimpleMessageChannelBuilder::createQueueChannel('ecotone_pulse'),
        ];
    }

    public function test_demo_ping_records_the_reply_of_ticketing_in_the_pong_log(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $storefront->runConsoleCommand('demo:ping', ['waitSeconds' => 0]);
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $storefront->run('storefront', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['pong from ticketing to storefront'], $storefront->getGateway(PongLog::class)->replies());
    }

    public function test_demo_ping_reports_that_no_reply_arrived_when_ticketing_does_not_answer_in_time(): void
    {
        $storefront = $this->storefront();

        $storefront->runConsoleCommand('demo:ping', ['waitSeconds' => 0]);

        self::assertStringStartsWith('No reply from ticketing within 0 seconds.', implode(PHP_EOL, $storefront->getInMemoryConsoleWriter()->getErrorLines()));
    }

    private function storefront(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [EcotoneConfiguration::class, DemoPing::class, PongLog::class],
            containerOrAvailableServices: [new EcotoneConfiguration(), new DemoPing(), DbalConnectionFactory::class => new DbalConnectionFactory(['dsn' => getenv('DATABASE_DSN'), 'connection' => ['application_name' => getenv('DB_APPLICATION_NAME')]])],
            extensionObjects: $this->channels,
            pathToRootCatalog: dirname(__DIR__, 2),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function ticketing(): FlowTestSupport
    {
        $ticketing = new class () {
            #[Distributed]
            #[CommandHandler('demo.ping')]
            public function ping(string $sender): string
            {
                return 'pong from ticketing to ' . $sender;
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$ticketing::class],
            containerOrAvailableServices: [$ticketing],
            extensionObjects: [
                ...$this->channels,
                ServiceConfiguration::createWithDefaults()->withServiceName('ticketing'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'storefront', channelName: 'storefront'),
            ],
            enabledModules: [],
            pathToRootCatalog: dirname(__DIR__, 2),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
