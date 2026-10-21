<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Ecotone\Api\Amqp\AmqpLibConnectionFactory;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @internal
 */
final class ChannelMissingTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    private const BROKER_DSN = 'amqp://guest:guest@rabbitmq:5672/%2f';

    public function test_a_service_whose_channel_does_not_exist_on_the_broker_is_shown_as_channel_missing_with_the_destination(): void
    {
        $client = $this->pulseOnAmqp();

        $crawler = $client->request('GET', '/services');

        self::assertResponseIsSuccessful();
        self::assertSame('channel_missing', $crawler->filter('[data-service="orders"] [data-status]')->attr('data-status'));
        self::assertStringContainsString(sprintf("'%s' does not exist", $this->serviceChannels['orders']), $crawler->filter('[data-service="orders"] [data-request-error]')->text());
    }

    public function test_the_service_becomes_connected_once_it_has_created_its_channel(): void
    {
        $client = $this->pulseOnAmqp();
        $client->request('GET', '/services');
        $this->runPulseWorker($client->getKernel());
        $orders = $this->amqpService('orders');
        $orders->run($this->serviceChannels['orders'], ExecutionPollingMetadata::createWithTestingSetup());

        $this->advanceTimeBy(60);
        $client->request('GET', '/services');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $crawler = $client->request('GET', '/services');

        self::assertSame('connected', $crawler->filter('[data-service="orders"] [data-status]')->attr('data-status'));
        self::assertCount(0, $crawler->filter('[data-service="orders"] [data-request-error]'));
    }

    public function test_a_missing_channel_is_asked_again_only_once_the_snapshot_freshness_window_has_passed(): void
    {
        $client = $this->pulseOnAmqp();
        $client->request('GET', '/services');
        $this->runPulseWorker($client->getKernel());
        $orders = $this->amqpService('orders');
        $orders->run($this->serviceChannels['orders'], ExecutionPollingMetadata::createWithTestingSetup());

        $crawler = $client->request('GET', '/services');
        self::assertSame('channel_missing', $crawler->filter('[data-service="orders"] [data-status]')->attr('data-status'));

        $this->advanceTimeBy(60);
        $crawler = $client->request('GET', '/services');
        self::assertSame('waiting', $crawler->filter('[data-service="orders"] [data-status]')->attr('data-status'));
    }

    private function pulseOnAmqp(): KernelBrowser
    {
        $suffix = uniqid();
        $this->pulseChannel = 'pulse_' . $suffix;
        $this->serviceChannels = ['orders' => 'orders_' . $suffix];
        $this->configurePulse([
            'PULSE_CHANNEL' => $this->pulseChannel,
            'PULSE_TRANSPORT_DSN' => self::BROKER_DSN,
            'PULSE_SERVICES' => 'orders:' . $this->serviceChannels['orders'],
        ]);

        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseInternalStorage($client->getKernel());

        return $client;
    }

    private function amqpService(string $service): FlowTestSupport
    {
        $connection = new AmqpExtConnectionFactory(['dsn' => self::BROKER_DSN]);

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [AmqpExtConnectionFactory::class => $connection, AmqpLibConnectionFactory::class => $connection],
            extensionObjects: [
                AmqpBackedMessageChannelBuilder::create($this->serviceChannels[$service])->withReceiveTimeout(1),
                AmqpBackedMessageChannelBuilder::create($this->pulseChannel),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: $this->pulseChannel),
                ServiceConfiguration::createWithDefaults()->withServiceName($service),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::AMQP_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
