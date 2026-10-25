<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\Brokers;
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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class NamedConnectionsTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;


    private string $suffix;

    public function test_services_on_two_connections_both_reach_connected_and_the_queue_override_receives_the_requests(): void
    {
        $client = $this->pulseOnDatabaseAndBroker();
        $billing = $this->billingOnBroker();
        $billing->run('billing_' . $this->suffix, ExecutionPollingMetadata::createWithTestingSetup());
        $orders = $this->realService('orders');

        $client->request('GET', '/services');
        $this->runService($orders, 'orders');
        $billing->run('billing_' . $this->suffix, ExecutionPollingMetadata::createWithTestingSetup());
        $this->runPulseWorker($client->getKernel(), $this->pulseChannel);
        $this->runPulseWorker($client->getKernel(), $this->pulseChannel . '.legacy');
        $crawler = $client->request('GET', '/services');

        self::assertSame('connected', $crawler->filter('[data-service="orders"] [data-status]')->attr('data-status'));
        self::assertSame('connected', $crawler->filter('[data-service="billing"] [data-status]')->attr('data-status'));
    }

    public function test_pulse_lists_a_reply_channel_to_consume_for_every_connection(): void
    {
        $client = $this->pulseOnDatabaseAndBroker();

        $command = new CommandTester((new Application($client->getKernel()))->find('pulse:reply-channels'));
        $command->execute([]);

        self::assertSame([$this->pulseChannel, $this->pulseChannel . '.legacy'], array_values(array_filter(explode("\n", $command->getDisplay()))));
    }

    private function pulseOnDatabaseAndBroker(): KernelBrowser
    {
        $this->suffix = uniqid();
        $this->pulseChannel = 'pulse_' . $this->suffix;
        $this->serviceChannels = ['orders' => 'orders_' . $this->suffix, 'billing' => 'billing_' . $this->suffix];
        $this->configurePulse([
            'PULSE_CHANNEL' => $this->pulseChannel,
            'PULSE_CONNECTION_LEGACY_DSN' => Brokers::amqpDsn(),
            'PULSE_SERVICES' => sprintf('orders:%s,billing:%s#billing_queue_%s@legacy', $this->serviceChannels['orders'], $this->serviceChannels['billing'], $this->suffix),
        ]);

        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseInternalStorage($client->getKernel());

        return $client;
    }

    private function billingOnBroker(): FlowTestSupport
    {
        $connection = new AmqpExtConnectionFactory(['dsn' => Brokers::amqpDsn()]);

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [AmqpExtConnectionFactory::class => $connection, AmqpLibConnectionFactory::class => $connection],
            extensionObjects: [
                AmqpBackedMessageChannelBuilder::create('billing_' . $this->suffix, queueName: 'billing_queue_' . $this->suffix)->withReceiveTimeout(1),
                AmqpBackedMessageChannelBuilder::create($this->pulseChannel),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: $this->pulseChannel),
                ServiceConfiguration::createWithDefaults()->withServiceName('billing'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::AMQP_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function runPulseWorker(\Symfony\Component\HttpKernel\KernelInterface $kernel, string $channel): void
    {
        $command = new CommandTester((new Application($kernel))->find('ecotone:run'));
        $command->execute(['consumerName' => $channel, '--finishWhenNoMessages' => true, '--executionTimeLimit' => 2000]);
    }
}
