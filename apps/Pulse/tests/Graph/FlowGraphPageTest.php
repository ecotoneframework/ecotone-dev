<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\MermaidDiagram;
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
}
