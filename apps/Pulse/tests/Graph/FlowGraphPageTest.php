<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\Diagram;
use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\Gateway\DistributedBus;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class FlowGraphPageTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_flow_graph_links_a_sent_command_to_the_handler_in_the_target_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertResponseIsSuccessful();
        self::assertTrue(Diagram::fromPage($crawler)->hasEdge('orders', 'invoice.create', 'distributed command', 'billing', 'invoice.create'));
    }

    public function test_flow_graph_links_a_published_event_to_the_subscribing_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertTrue((Diagram::fromPage($crawler))->hasEdge('billing', 'invoice.created', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_flow_graph_keeps_inferred_edges_dashed(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertTrue((Diagram::fromPage($crawler))->hasEdge('orders', 'invoice.create', 'sends to billing', 'orders', 'Distributed Bus', Diagram::DASHED));
    }

    public function test_flow_graph_hides_services_that_are_toggled_off(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow?services[]=orders');

        $diagram = Diagram::fromPage($crawler);
        self::assertSame(['orders'], $diagram->services);
        self::assertStringNotContainsString('invoice.create.handler', $diagram->text);
        self::assertStringNotContainsString('distributed command', $diagram->text);
        self::assertNotContains('distributed command', array_column($diagram->edges, 'label'));
        self::assertCount(1, $crawler->filter('input[name="services[]"]:checked'));
    }

    public function test_hostile_labels_from_a_snapshot_cannot_inject_markup_or_break_the_diagram(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->serviceHandlingHostileCommand('orders'), 'billing' => $this->serviceHandlingCommandX('billing')]);

        $crawler = $client->request('GET', '/flow');

        self::assertResponseIsSuccessful();
        $diagram = Diagram::fromPage($crawler);
        self::assertStringNotContainsString('<img', (string) $client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-diagram] img, [data-diagram] [onerror]'));
        self::assertCount(1, array_filter($diagram->nodes, fn (array $node) => $node['name'] === 'A<img src=x onerror=alert(1)> "B]'));
        self::assertCount(count($diagram->nodes), $crawler->filter('[data-diagram-node]'));
        foreach (array_keys($diagram->nodes) as $id) {
            self::assertMatchesRegularExpression('/^s\d+n\d+$/', $id);
        }
        self::assertNotEmpty($diagram->edges);
        foreach ($diagram->edges as $edge) {
            self::assertArrayHasKey($edge['from'], $diagram->nodes);
            self::assertArrayHasKey($edge['to'], $diagram->nodes);
            self::assertContains($edge['arrow'], [Diagram::SOLID, Diagram::DASHED, Diagram::THICK]);
        }
    }

    public function test_services_whose_names_differ_only_by_punctuation_or_are_reserved_words_stay_apart(): void
    {
        $this->configurePulseWithServices('a-b', 'a_b', 'end');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['a-b' => $this->serviceHandlingCommandX('a-b'), 'a_b' => $this->serviceHandlingCommandX('a_b'), 'end' => $this->serviceHandlingCommandX('end')]);

        $crawler = $client->request('GET', '/flow');

        $diagram = Diagram::fromPage($crawler);
        self::assertEqualsCanonicalizing(['a-b', 'a_b', 'end'], $diagram->services);
        $groups = $crawler->filter('[data-diagram-group]')->each(fn (Crawler $group) => (string) $group->attr('data-diagram-group'));
        self::assertCount(3, array_unique($groups));
        self::assertSame($groups, preg_grep('/^s\d+$/', $groups));
        self::assertSame($diagram->nodesOf('a-b'), $diagram->nodesOf('a_b'));
        self::assertGreaterThan(0, $diagram->nodesOf('end'));
        self::assertCount(count($diagram->nodes), $crawler->filter('[data-diagram-node]'));
    }

    public function test_flow_graph_links_a_sent_message_to_the_channel_handler_and_its_reply_back(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingMessageToBilling(), 'billing' => $this->billingAnsweringPing()]);

        $crawler = $client->request('GET', '/flow');

        $diagram = Diagram::fromPage($crawler);
        self::assertTrue($diagram->hasEdge('orders', '::place', 'distributed message', 'billing', '::ping', Diagram::THICK));
        self::assertTrue($diagram->hasEdge('billing', '::ping', 'reply', 'orders', '::place', Diagram::DASHED));
    }

    public function test_flow_graph_draws_no_reply_for_a_message_sent_without_a_reply_routing_key(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $orders = new class () {
            public function __construct(private ?DistributedBus $distributedBus = null)
            {
            }

            #[CommandHandler('order.ping', endpointId: 'order.ping.handler')]
            public function place(array $payload): void
            {
                $this->distributedBus->sendMessage('billing', 'billing.ping', '{}');
            }
        };
        $this->pulseHavingHeardFrom($client, ['orders' => $this->distributedService('orders', $orders, DistributedServiceMap::initialize()->withCommandMapping('billing', $this->serviceChannels['billing'])), 'billing' => $this->billingAnsweringPing()]);

        $diagram = Diagram::fromPage($client->request('GET', '/flow'));

        self::assertTrue($diagram->hasEdge('orders', '::place', 'distributed message', 'billing', '::ping', Diagram::THICK));
        self::assertFalse($diagram->hasEdge('billing', '::ping', 'reply', 'orders', ''));
    }

    public function test_event_subscription_with_a_wildcard_links_the_published_event(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $orders = $this->ordersSendingInvoiceCommandToBilling(DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['orders'], ['*.created']));
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertTrue(Diagram::fromPage($crawler)->hasEdge('billing', 'invoice.created', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_event_subscription_keys_match_published_routing_keys_ignoring_case(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $orders = $this->ordersSendingInvoiceCommandToBilling(DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['orders'], ['INVOICE.*']));
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertTrue(Diagram::fromPage($crawler)->hasEdge('billing', 'invoice.created', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_event_is_not_linked_when_the_event_mapping_excludes_the_publishing_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $orders = $this->ordersSendingInvoiceCommandToBilling();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $this->billingCreatingInvoices(DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['orders'], ['invoice.*'], excludePublishingServices: ['billing']))]);

        $crawler = $client->request('GET', '/flow');

        self::assertFalse(Diagram::fromPage($crawler)->hasEdge('billing', 'invoice.created', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_event_is_linked_only_when_the_event_mapping_includes_the_publishing_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $orders = $this->ordersSendingInvoiceCommandToBilling();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $this->billingCreatingInvoices(DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['orders'], ['invoice.*'], includePublishingServices: ['shipping']))]);

        self::assertFalse(Diagram::fromPage($client->request('GET', '/flow'))->hasEdge('billing', 'invoice.created', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_event_is_linked_when_the_event_mapping_includes_the_publishing_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $orders = $this->ordersSendingInvoiceCommandToBilling();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $this->billingCreatingInvoices(DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['orders'], ['invoice.*'], includePublishingServices: ['billing']))]);

        self::assertTrue(Diagram::fromPage($client->request('GET', '/flow'))->hasEdge('billing', 'invoice.created', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_services_beyond_the_node_limit_open_as_the_service_overview(): void
    {
        $names = ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8'];
        $this->configurePulseWithServices(...$names);
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, array_combine($names, array_map(fn (string $name) => $this->serviceHandlingCommandX($name), $names)));

        $crawler = $client->request('GET', '/flow');

        self::assertCount(1, $crawler->filter('[data-flow-overview]'));
        self::assertSame(0, Diagram::fromPage($crawler)->nodesOf('s1'));
        $chosen = Diagram::fromPage($client->request('GET', '/flow?services[]=s2&services[]=s3'));
        self::assertGreaterThan(0, $chosen->nodesOf('s2'));
        self::assertGreaterThan(0, $chosen->nodesOf('s3'));
        self::assertSame(0, $chosen->nodesOf('s1'));
    }

    public function test_services_within_the_node_limit_are_all_shown(): void
    {
        $this->configurePulseWithServices('s1', 's2');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['s1' => $this->serviceHandlingCommandX('s1'), 's2' => $this->serviceHandlingCommandX('s2')]);

        $crawler = $client->request('GET', '/flow');

        self::assertCount(0, $crawler->filter('[data-notice="narrowed"]'));
        self::assertGreaterThan(0, Diagram::fromPage($crawler)->nodesOf('s2'));
    }

    public function test_a_snapshot_that_cannot_be_read_is_reported_instead_of_vanishing(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $broken = json_encode(['schemaVersion' => 1, 'serviceName' => 'orders', 'overview' => [], 'flowGraph' => ['nodes' => [['id' => 'a', 'kind' => 'channel', 'label' => 'a', 'attributes' => []]], 'edges' => [['from' => 'a', 'to' => 'missing', 'kind' => 'input', 'attributes' => []]]], 'topology' => ['serviceMaps' => []]], JSON_THROW_ON_ERROR);
        $this->pulseHavingHeardFrom($client, ['orders' => $this->serviceAnsweringWith('orders', $broken), 'billing' => $this->serviceHandlingCommandX('billing')]);

        $crawler = $client->request('GET', '/flow');

        self::assertStringContainsString('orders: snapshot could not be read', $crawler->filter('[data-notice="unreadable"]')->text());
        self::assertGreaterThan(0, Diagram::fromPage($crawler)->nodesOf('billing'));
    }
}
