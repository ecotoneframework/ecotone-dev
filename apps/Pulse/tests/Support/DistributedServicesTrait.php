<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
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

    private function distributedService(string $service, object $handler, DistributedServiceMap $serviceMap): FlowTestSupport
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
