<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\MermaidDiagram;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @internal
 */
final class FannedOutEventsTest extends WebTestCase
{
    use DistributedServicesTrait;

    public function test_flow_graph_links_an_event_published_by_one_service_to_every_subscribing_service(): void
    {
        $diagram = $this->diagramWithFanOut('/flow?services[]=orders&services[]=billing&services[]=shipping&services[]=audit');

        foreach (['billing', 'shipping', 'audit'] as $subscriber) {
            self::assertTrue($diagram->hasEdge('orders', 'order.placed', 'distributed event', $subscriber, 'order.placed'));
        }
    }

    public function test_topology_diagram_draws_the_services_the_event_reaches(): void
    {
        $diagram = $this->diagramWithFanOut('/topology');

        foreach (['billing', 'shipping', 'audit'] as $subscriber) {
            self::assertStringContainsString($subscriber, $diagram->source);
        }
    }

    private function diagramWithFanOut(string $uri): MermaidDiagram
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping', 'audit');
        $client = $this->pulseWebClient();
        $orders = new class () {
            #[\Ecotone\Api\Attribute\CommandHandler('order.place', endpointId: 'order.place.handler')]
            public function place(array $payload, \Ecotone\Api\Gateway\DistributedBus $distributedBus): void
            {
                $distributedBus->publishEvent('order.placed', '{}');
            }
        };
        $services = [
            'orders' => $this->distributedService('orders', $orders, DistributedServiceMap::initialize()
                ->withCommandMapping('billing', $this->serviceChannels['billing'])
                ->withEventMapping($this->serviceChannels['billing'], ['order.*'])
                ->withEventMapping($this->serviceChannels['shipping'], ['order.*'])
                ->withEventMapping($this->serviceChannels['audit'], ['order.*'])),
            'billing' => $this->subscriber('billing'),
            'shipping' => $this->subscriber('shipping'),
            'audit' => $this->subscriber('audit'),
        ];
        $this->pulseHavingHeardFrom($client, $services);
        $client->request('GET', $uri);
        $this->pulseHavingHeardFrom($client, $services);
        $crawler = $client->request('GET', $uri);

        return MermaidDiagram::fromPage($crawler);
    }

    private function subscriber(string $service): FlowTestSupport
    {
        $handler = new class () {
            #[Distributed]
            #[EventHandler('order.placed', endpointId: 'order.placed.handler')]
            public function whenPlaced(string $payload): void
            {
            }
        };

        return $this->distributedService($service, $handler, DistributedServiceMap::initialize());
    }
}
