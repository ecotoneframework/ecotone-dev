<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait TracedServicesTrait
{
    use PulseServicesTrait;

    private const ASYNC_CHANNELS = ['orders_async', 'billing_async'];

    /**
     * @var array<string, FlowTestSupport>
     */
    private array $tracedServices = [];

    private function pulseWebClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        return $client;
    }

    /**
     * @param array<string, FlowTestSupport> $services
     */
    private function pulseHavingHeardFrom(KernelBrowser $client, array $services): void
    {
        $client->request('GET', '/');
        foreach ($services as $name => $service) {
            $this->runService($service, $name);
        }
        $this->runPulseWorker($client->getKernel());
    }

    private function tracedOrders(bool $tracing = true): FlowTestSupport
    {
        $orders = new class () {
            #[CommandHandler('order.place', endpointId: 'order.place.handler')]
            public function place(array $order, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('order.notify', $order);
            }

            #[Asynchronous('orders_async')]
            #[CommandHandler('order.notify', endpointId: 'order.notify.handler')]
            public function notify(array $order): void
            {
            }

            #[Asynchronous('orders_async')]
            #[CommandHandler('order.explode', endpointId: 'order.explode.handler')]
            public function explode(array $order): void
            {
                throw new RuntimeException('Order ' . $order['orderId'] . ' exploded <script>alert(1)</script>');
            }

            #[CommandHandler('order.invoice', endpointId: 'order.invoice.handler')]
            public function invoice(array $order, DistributedBus $distributedBus): void
            {
                $distributedBus->sendCommand('billing', 'invoice.create', '{}');
            }

            #[CommandHandler('order.announce', endpointId: 'order.announce.handler')]
            public function announce(array $order, DistributedBus $distributedBus): void
            {
                $distributedBus->publishEvent('order.announced', '{}');
            }
        };

        return $this->tracedService('orders', $orders, DistributedServiceMap::initialize()
            ->withCommandMapping('billing', $this->serviceChannels['billing'])
            ->withEventMapping($this->serviceChannels['billing'], ['order.*'])
            ->withEventMapping($this->serviceChannels['shipping'], ['order.*']), $tracing);
    }

    private function tracedBilling(): FlowTestSupport
    {
        $billing = new class () {
            #[Distributed]
            #[CommandHandler('invoice.create', endpointId: 'invoice.create.handler')]
            public function create(string $payload): void
            {
            }

            #[Distributed]
            #[Asynchronous('billing_async')]
            #[CommandHandler('invoice.create.later', endpointId: 'invoice.create.later.handler')]
            public function createLater(string $payload): void
            {
            }

            #[Distributed]
            #[EventHandler('order.announced', endpointId: 'billing.order.announced')]
            public function whenAnnounced(string $payload): void
            {
            }
        };

        return $this->tracedService('billing', $billing, DistributedServiceMap::initialize());
    }

    private function tracedShipping(): FlowTestSupport
    {
        $shipping = new class () {
            #[Distributed]
            #[EventHandler('order.announced', endpointId: 'shipping.order.announced')]
            public function whenAnnounced(string $payload): void
            {
            }
        };

        return $this->tracedService('shipping', $shipping, DistributedServiceMap::initialize());
    }

    private function tracedService(string $service, object $handler, DistributedServiceMap $serviceMap, bool $tracing = true): FlowTestSupport
    {
        $leftOver = $this->bootstrapService($service, $handler, $serviceMap, false);
        foreach (self::ASYNC_CHANNELS as $channel) {
            $leftOver->run($channel, ExecutionPollingMetadata::createWithTestingSetup(1000, 300, false));
        }

        return $this->tracedServices[$service] = $this->bootstrapService($service, $handler, $serviceMap, $tracing);
    }

    private function bootstrapService(string $service, object $handler, DistributedServiceMap $serviceMap, bool $tracing): FlowTestSupport
    {
        $asyncChannels = self::ASYNC_CHANNELS;
        $application = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory($_SERVER['PULSE_TRANSPORT_DSN'])],
            extensionObjects: [
                ...array_map(fn (string $channel) => DbalBackedMessageChannelBuilder::create($channel)->withReceiveTimeout(1), array_values($this->serviceChannels)),
                ...array_map(fn (string $channel) => DbalBackedMessageChannelBuilder::create($channel)->withReceiveTimeout(1), $asyncChannels),
                DbalBackedMessageChannelBuilder::create($this->pulseChannel),
                $serviceMap->withCommandMapping('ecotone_pulse', $this->pulseChannel),
                ServiceConfiguration::createWithDefaults()->withServiceName($service)->withDefaultErrorChannel('dbal_dead_letter'),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                ...($tracing ? [FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage(true)] : []),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $application->getGateway(DeadLetterGateway::class)->deleteAll();

        return $application;
    }

    private function consume(string $service, string $channel): void
    {
        $this->tracedServices[$service]->run($channel, ExecutionPollingMetadata::createWithTestingSetup(10, 100, false));
    }

    private function deliverEverywhere(): void
    {
        foreach (array_keys($this->tracedServices) as $name) {
            $this->consume($name, $this->serviceChannels[$name]);
        }
    }
}
