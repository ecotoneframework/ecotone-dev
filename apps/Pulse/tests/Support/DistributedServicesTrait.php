<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait DistributedServicesTrait
{
    use PulseServicesTrait;

    private function ordersSendingInvoiceCommandToBilling(?DistributedServiceMap $serviceMap = null): FlowTestSupport
    {
        $orders = new class () {
            public function __construct(private ?DistributedBus $distributedBus = null)
            {
            }

            #[CommandHandler('order.place', endpointId: 'order.place.handler')]
            public function place(array $payload): void
            {
                $this->distributedBus->sendCommand('billing', 'invoice.create', '{}');
            }

            #[Distributed]
            #[EventHandler('invoice.created', endpointId: 'invoice.created.handler')]
            public function whenInvoiceCreated(array $payload): void
            {
            }
        };

        return $this->distributedService('orders', $orders, $serviceMap ?? DistributedServiceMap::initialize()
            ->withCommandMapping('billing', $this->serviceChannels['billing'])
            ->withEventMapping($this->serviceChannels['orders'], ['invoice.*']));
    }

    private function ordersWithMismatches(): FlowTestSupport
    {
        $orders = new class () {
            public function __construct(private ?DistributedBus $distributedBus = null)
            {
            }

            #[CommandHandler('order.place', endpointId: 'order.place.handler')]
            public function place(array $payload): void
            {
                $this->distributedBus->sendCommand('billing', 'invoice.create', '{}');
                $this->distributedBus->sendCommand('shipping', 'parcel.ship', '{}');
                $this->distributedBus->publishEvent('order.placed', '{}');
            }
        };

        return $this->distributedService('orders', $orders, DistributedServiceMap::initialize()
            ->withCommandMapping('shipping', $this->serviceChannels['orders'])
            ->withEventMapping($this->serviceChannels['orders'], ['refund.*']));
    }

    private function ordersMappingCommandsTo(string ...$targets): FlowTestSupport
    {
        $orders = new class () {
        };
        $serviceMap = DistributedServiceMap::initialize();
        foreach ($targets as $target) {
            $serviceMap = $serviceMap->withCommandMapping($target, $this->serviceChannels[$target]);
        }

        return $this->distributedService('orders', $orders, $serviceMap);
    }

    private function ordersSendingMessageToBilling(): FlowTestSupport
    {
        $orders = new class () {
            public function __construct(private ?DistributedBus $distributedBus = null)
            {
            }

            #[CommandHandler('order.ping', endpointId: 'order.ping.handler')]
            public function place(array $payload): void
            {
                $this->distributedBus->sendMessage('billing', 'billing.ping', '{}', replyRoutingKey: 'billing.pong');
            }
        };

        return $this->distributedService('orders', $orders, DistributedServiceMap::initialize()->withCommandMapping('billing', $this->serviceChannels['billing']));
    }

    private function billingAnsweringPing(): FlowTestSupport
    {
        $billing = new class () {
            #[Distributed]
            #[InternalHandler(inputChannelName: 'billing.ping', endpointId: 'billing.ping.handler')]
            public function ping(string $payload): string
            {
                return 'pong';
            }
        };

        return $this->distributedService('billing', $billing, DistributedServiceMap::initialize());
    }

    private function serviceHandlingAsynchronousCommand(string $service): FlowTestSupport
    {
        $handler = new class () {
            #[Asynchronous('slow_work')]
            #[CommandHandler('work.do', endpointId: 'work.do.handler')]
            public function handle(array $payload): void
            {
            }
        };

        return $this->distributedService($service, $handler, DistributedServiceMap::initialize(), [SimpleMessageChannelBuilder::createQueueChannel('slow_work')]);
    }

    private function serviceHandlingCommandX(string $service): FlowTestSupport
    {
        $handler = new class () {
            #[Distributed]
            #[CommandHandler('x', endpointId: 'x.handler')]
            public function handle(array $payload): void
            {
            }
        };

        return $this->distributedService($service, $handler, DistributedServiceMap::initialize());
    }

    private function serviceHandlingHostileCommand(string $service): FlowTestSupport
    {
        $handler = new class () {
            #[Distributed]
            #[CommandHandler("A<img src=x onerror=alert(1)>\n\"B]", endpointId: 'hostile.handler')]
            public function handle(array $payload): void
            {
            }
        };

        return $this->distributedService($service, $handler, DistributedServiceMap::initialize());
    }

    private function billingCreatingInvoices(?DistributedServiceMap $serviceMap = null): FlowTestSupport
    {
        $billing = new class () {
            public function __construct(private ?DistributedBus $distributedBus = null)
            {
            }

            #[Distributed]
            #[CommandHandler('invoice.create', endpointId: 'invoice.create.handler')]
            public function create(array $payload): void
            {
                $this->distributedBus->publishEvent('invoice.created', '{}');
            }
        };

        return $this->distributedService('billing', $billing, $serviceMap ?? DistributedServiceMap::initialize()
            ->withEventMapping($this->serviceChannels['orders'], ['invoice.*']));
    }

    /**
     * @param array<object> $extensionObjects
     */
    private function distributedService(string $service, object $handler, DistributedServiceMap $serviceMap, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => new DbalConnectionFactory($_SERVER['PULSE_TRANSPORT_DSN'])],
            extensionObjects: [
                ...array_map(
                    fn (string $channel) => DbalBackedMessageChannelBuilder::create($channel)->withReceiveTimeout(1),
                    array_values($this->serviceChannels),
                ),
                DbalBackedMessageChannelBuilder::create($this->pulseChannel),
                $serviceMap->withCommandMapping('ecotone_pulse', $this->pulseChannel),
                ServiceConfiguration::createWithDefaults()->withServiceName($service),
                ...$extensionObjects,
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

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
}
