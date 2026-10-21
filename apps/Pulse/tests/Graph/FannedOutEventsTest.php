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

    public function test_topology_draws_the_event_edge_to_a_subscriber_that_sent_no_flow_graph(): void
    {
        $this->configurePulseWithServices('orders', 'shipping');
        $client = $this->pulseWebClient();
        $services = [
            'orders' => $this->publishingOrders(DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['shipping'], ['order.*'])),
            'shipping' => $this->serviceAnsweringWith('shipping', '{"schemaVersion":1,"serviceName":"shipping"}'),
        ];
        $this->pulseHavingHeardFrom($client, $services);
        $crawler = $client->request('GET', '/topology');

        self::assertStringContainsString('t0 -.->|"events order.*"| t1', MermaidDiagram::fromPage($crawler)->source);
        self::assertStringContainsString('["shipping"]', MermaidDiagram::fromPage($crawler)->source);
    }

    public function test_an_event_mapping_excluding_the_publisher_draws_no_edge_and_is_reported_in_both_rules(): void
    {
        $mismatches = $this->mismatchesOf('excluding');

        self::assertContains('unsubscribed-event', $mismatches);
        self::assertContains('unpublished-subscription', $mismatches);
    }

    public function test_an_event_mapping_including_only_other_publishers_draws_no_edge_and_is_reported_in_both_rules(): void
    {
        $mismatches = $this->mismatchesOf('including');

        self::assertContains('unsubscribed-event', $mismatches);
        self::assertContains('unpublished-subscription', $mismatches);
    }

    public function test_a_permitted_event_mapping_is_reported_by_neither_rule(): void
    {
        $mismatches = $this->mismatchesOf('permitting');

        self::assertSame([], $mismatches);
    }

    /**
     * @return list<string>
     */
    private function mismatchesOf(string $case): array
    {
        $this->configurePulseWithServices('orders', 'audit');
        $client = $this->pulseWebClient();
        $map = match ($case) {
            'excluding' => DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['audit'], ['order.*'], excludePublishingServices: ['orders']),
            'including' => DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['audit'], ['order.*'], includePublishingServices: ['billing']),
            default => DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['audit'], ['order.*'], includePublishingServices: ['orders']),
        };
        $this->pulseHavingHeardFrom($client, ['orders' => $this->publishingOrders($map), 'audit' => $this->subscriber('audit')]);

        return $client->request('GET', '/topology')->filter('[data-mismatch]')->each(static fn ($mismatch) => $mismatch->attr('data-mismatch'));
    }

    private function publishingOrders(DistributedServiceMap $map): FlowTestSupport
    {
        $orders = new class () {
            #[\Ecotone\Api\Attribute\CommandHandler('order.place', endpointId: 'order.place.handler')]
            public function place(array $payload, \Ecotone\Api\Gateway\DistributedBus $distributedBus): void
            {
                $distributedBus->publishEvent('order.placed', '{}');
            }
        };

        return $this->distributedService('orders', $orders, $map);
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
