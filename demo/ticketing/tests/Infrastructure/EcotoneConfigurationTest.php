<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use App\Infrastructure\EcotoneConfiguration;
use App\Ping\PingHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class EcotoneConfigurationTest extends TestCase
{
    public function test_ticketing_answers_a_distributed_ping_on_the_reply_routing_key_of_the_sender(): void
    {
        $channels = [
            SimpleMessageChannelBuilder::createQueueChannel('ticketing'),
            SimpleMessageChannelBuilder::createQueueChannel('storefront'),
            SimpleMessageChannelBuilder::createQueueChannel('ticketing_async'),
            SimpleMessageChannelBuilder::createQueueChannel('ecotone_pulse'),
        ];
        $storefront = new class () {
            public array $pongs = [];

            #[InternalHandler('demo.pong')]
            public function pong(string $reply): void
            {
                $this->pongs[] = $reply;
            }
        };
        $ticketing = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [EcotoneConfiguration::class, PingHandler::class],
            containerOrAvailableServices: [new EcotoneConfiguration(), new PingHandler(), DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: $channels,
            pathToRootCatalog: dirname(__DIR__, 2),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $storefrontService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$storefront::class],
            containerOrAvailableServices: [$storefront],
            extensionObjects: [
                ...$channels,
                ServiceConfiguration::createWithDefaults()->withServiceName('storefront'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticketing', channelName: 'ticketing'),
            ],
            enabledModules: [],
            pathToRootCatalog: dirname(__DIR__, 2),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $storefrontService->getDistributedBus()->sendMessage('ticketing', 'demo.ping', 'storefront', replyRoutingKey: 'demo.pong');
        $ticketing->run('ticketing', ExecutionPollingMetadata::createWithTestingSetup());
        $storefrontService->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['pong from ticketing to storefront'], $storefront->pongs);
    }
}
