<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
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
        $diagram = $crawler->filter('pre.mermaid')->text();
        self::assertStringContainsString('subgraph', $diagram);
        self::assertMatchesRegularExpression('/orders__message_invoice_create ==>\|"distributed command"\| billing__message_invoice_create/', $diagram);
    }

    public function test_flow_graph_links_a_published_event_to_the_subscribing_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertMatchesRegularExpression('/billing__message_invoice_created ==>\|"distributed event"\| orders__message_invoice_created/', $crawler->filter('pre.mermaid')->text());
    }

    public function test_flow_graph_keeps_inferred_edges_dashed(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertMatchesRegularExpression('/-\.->\|"sends"\| orders__distributed_/', $crawler->filter('pre.mermaid')->text());
    }

    public function test_flow_graph_hides_services_that_are_toggled_off(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow?services[]=orders');

        $diagram = $crawler->filter('pre.mermaid')->text();
        self::assertStringContainsString('subgraph orders', $diagram);
        self::assertStringNotContainsString('billing__', $diagram);
        self::assertStringNotContainsString('distributed command', $diagram);
        self::assertCount(1, $crawler->filter('input[name="services[]"]:checked'));
    }
}
