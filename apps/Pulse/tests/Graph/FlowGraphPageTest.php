<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\MermaidDiagram;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @internal
 */
final class FlowGraphPageTest extends WebTestCase
{
    use DistributedServicesTrait;

    public function test_flow_graph_links_a_sent_command_to_the_handler_in_the_target_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertResponseIsSuccessful();
        $diagram = MermaidDiagram::fromPage($crawler)->source;
        self::assertTrue((new MermaidDiagram($diagram))->hasEdge('orders', 'invoice.create', 'distributed command', 'billing', 'invoice.create'));
    }

    public function test_flow_graph_links_a_published_event_to_the_subscribing_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertTrue((MermaidDiagram::fromPage($crawler))->hasEdge('billing', 'invoice.created', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_flow_graph_keeps_inferred_edges_dashed(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertTrue((MermaidDiagram::fromPage($crawler))->hasEdge('orders', 'invoice.create', 'sends', 'orders', 'Distributed Bus', '-.->'));
    }

    public function test_flow_graph_hides_services_that_are_toggled_off(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow?services[]=orders');

        $diagram = MermaidDiagram::fromPage($crawler)->source;
        self::assertStringContainsString('subgraph', $diagram);
        self::assertStringContainsString('["orders"]', $diagram);
        self::assertStringNotContainsString('["billing"]', $diagram);
        self::assertStringNotContainsString('invoice.create.handler', $diagram);
        self::assertStringNotContainsString('distributed command', $diagram);
        self::assertCount(1, $crawler->filter('input[name="services[]"]:checked'));
    }

    public function test_hostile_labels_from_a_snapshot_cannot_inject_markup_or_break_the_diagram(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->serviceHandlingHostileCommand('orders'), 'billing' => $this->serviceHandlingCommandX('billing')]);

        $crawler = $client->request('GET', '/flow');

        self::assertResponseIsSuccessful();
        $diagram = MermaidDiagram::fromPage($crawler);
        self::assertStringNotContainsString('<img', $diagram->source);
        self::assertStringNotContainsString('onerror=alert(1)>', $diagram->source);
        $hostile = array_values(array_filter($diagram->lines, fn (string $line) => str_contains($line, 'onerror')));
        self::assertCount(1, array_filter($hostile, fn (string $line) => str_contains($line, '#93;')));
        foreach ($diagram->lines as $line) {
            self::assertMatchesRegularExpression('/^(flowchart LR|    subgraph \w+ \["[^"]*"\]|    end|        \w+\S*"[^"]*"\S*|    \w+ (-->|-\.->|==>)(\|"[^"]*"\|)? \w+)$/', $line);
        }
    }

    public function test_services_whose_names_differ_only_by_punctuation_or_are_reserved_words_stay_apart(): void
    {
        $this->configurePulseWithServices('a-b', 'a_b', 'end');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['a-b' => $this->serviceHandlingCommandX('a-b'), 'a_b' => $this->serviceHandlingCommandX('a_b'), 'end' => $this->serviceHandlingCommandX('end')]);

        $crawler = $client->request('GET', '/flow');

        $diagram = MermaidDiagram::fromPage($crawler);
        $subgraphs = array_values(array_filter($diagram->lines, fn (string $line) => str_contains($line, 'subgraph')));
        self::assertCount(3, array_unique($subgraphs));
        self::assertCount(3, array_unique(array_map(fn (string $line) => explode(' ', trim($line))[1], $subgraphs)));
        self::assertNotContains(true, array_map(fn (string $line) => (bool) preg_match('/^    (end|graph|subgraph|flowchart)\b ?\[/', $line), $diagram->lines));
        self::assertSame($diagram->nodesOf('a-b'), $diagram->nodesOf('a_b'));
        self::assertGreaterThan(0, $diagram->nodesOf('end'));
        self::assertCount(count($diagram->nodes), array_unique(array_keys($diagram->nodes)));
    }

    public function test_flow_graph_links_a_sent_message_to_the_channel_handler_and_its_reply_back(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingMessageToBilling(), 'billing' => $this->billingAnsweringPing()]);

        $crawler = $client->request('GET', '/flow');

        $diagram = MermaidDiagram::fromPage($crawler);
        self::assertTrue($diagram->hasEdge('orders', '::place', 'distributed message', 'billing', '::ping', '==>'));
        self::assertTrue($diagram->hasEdge('billing', '::ping', 'reply', 'orders', '::place', '-.->'));
    }

    public function test_event_subscription_with_a_wildcard_links_the_published_event(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $orders = $this->ordersSendingInvoiceCommandToBilling(DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['orders'], ['*.created']));
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertTrue(MermaidDiagram::fromPage($crawler)->hasEdge('billing', 'invoice.created', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_event_subscription_keys_match_published_routing_keys_ignoring_case(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $orders = $this->ordersSendingInvoiceCommandToBilling(DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['orders'], ['INVOICE.*']));
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertTrue(MermaidDiagram::fromPage($crawler)->hasEdge('billing', 'invoice.created', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_event_is_not_linked_when_the_subscriber_excludes_the_publishing_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $orders = $this->ordersSendingInvoiceCommandToBilling(DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['orders'], ['invoice.*'], excludePublishingServices: ['billing']));
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertFalse(MermaidDiagram::fromPage($crawler)->hasEdge('billing', 'invoice.created', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_event_is_linked_only_when_the_subscriber_includes_the_publishing_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $orders = $this->ordersSendingInvoiceCommandToBilling(DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['orders'], ['invoice.*'], includePublishingServices: ['shipping']));
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $this->billingCreatingInvoices()]);

        self::assertFalse(MermaidDiagram::fromPage($client->request('GET', '/flow'))->hasEdge('billing', 'invoice.created', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_event_is_linked_when_the_subscriber_includes_the_publishing_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $orders = $this->ordersSendingInvoiceCommandToBilling(DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['orders'], ['invoice.*'], includePublishingServices: ['billing']));
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $this->billingCreatingInvoices()]);

        self::assertTrue(MermaidDiagram::fromPage($client->request('GET', '/flow'))->hasEdge('billing', 'invoice.created', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_services_beyond_the_node_limit_default_to_the_first_service_with_a_message(): void
    {
        $names = ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8'];
        $this->configurePulseWithServices(...$names);
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, array_combine($names, array_map(fn (string $name) => $this->serviceHandlingCommandX($name), $names)));

        $crawler = $client->request('GET', '/flow');

        self::assertStringContainsString('only s1 is shown', $crawler->filter('[data-notice="narrowed"]')->text());
        $diagram = MermaidDiagram::fromPage($crawler);
        self::assertGreaterThan(0, $diagram->nodesOf('s1'));
        self::assertSame(0, $diagram->nodesOf('s2'));
        $chosen = MermaidDiagram::fromPage($client->request('GET', '/flow?services[]=s2&services[]=s3'));
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
        self::assertGreaterThan(0, MermaidDiagram::fromPage($crawler)->nodesOf('s2'));
    }

    public function test_a_snapshot_that_cannot_be_read_is_reported_instead_of_vanishing(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $broken = json_encode(['schemaVersion' => 1, 'serviceName' => 'orders', 'overview' => [], 'flowGraph' => ['nodes' => [['id' => 'a', 'kind' => 'not-a-kind', 'label' => 'a', 'attributes' => []]], 'edges' => []], 'topology' => ['serviceMaps' => []]], JSON_THROW_ON_ERROR);
        $this->pulseHavingHeardFrom($client, ['orders' => $this->serviceAnsweringWith('orders', $broken), 'billing' => $this->serviceHandlingCommandX('billing')]);

        $crawler = $client->request('GET', '/flow');

        self::assertStringContainsString('orders: snapshot could not be read', $crawler->filter('[data-notice="unreadable"]')->text());
        self::assertGreaterThan(0, MermaidDiagram::fromPage($crawler)->nodesOf('billing'));
    }
}
