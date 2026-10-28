<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\MermaidDiagram;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @internal
 */
final class FlowGraphCollapseTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_a_collapsed_service_is_drawn_as_one_node_carrying_its_distributed_arrows(): void
    {
        $client = $this->ordersAndBilling();

        $diagram = MermaidDiagram::fromPage($client->request('GET', '/flow?collapse[]=billing'));

        self::assertSame(['billing<br/>6 nodes, collapsed'], array_values(array_column(array_filter($diagram->nodes, fn (array $node) => $node['service'] === 'billing'), 'label')));
        self::assertGreaterThan(1, $diagram->nodesOf('orders'));
        self::assertTrue($diagram->hasEdge('orders', 'invoice.create', 'distributed command', 'billing', 'collapsed'));
        self::assertTrue($diagram->hasEdge('billing', 'collapsed', 'distributed event', 'orders', 'invoice.created'));
    }

    public function test_each_service_has_a_link_collapsing_it_and_a_collapsed_service_links_back_to_its_detail(): void
    {
        $client = $this->ordersAndBilling();

        $page = $client->request('GET', '/flow');
        self::assertSame('/flow?collapse%5B0%5D=billing', $page->filter('[data-collapse="billing"]')->attr('href'));

        $collapsed = $client->request('GET', '/flow?collapse[]=billing');
        self::assertCount(0, $collapsed->filter('[data-collapse="billing"]'));
        self::assertSame('/flow', $collapsed->filter('[data-expand="billing"]')->attr('href'));
        $nodes = json_decode($collapsed->filter('pre.mermaid')->attr('data-graph-nodes'), true, flags: JSON_THROW_ON_ERROR);
        $collapsedNode = array_values(array_filter($nodes, fn (array $node) => $node['service'] === 'billing' && str_contains($node['title'], 'collapsed')));
        self::assertSame('/flow', $collapsedNode[0]['href']);
    }

    public function test_collapsing_a_service_opens_the_detail_where_the_service_overview_would_open(): void
    {
        $others = ['svc01', 'svc02', 'svc03', 'svc04', 'svc05'];
        $this->configurePulseWithServices('orders', 'billing', ...$others);
        $client = $this->pulseWebClient();
        $services = ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()];
        foreach ($others as $other) {
            $services[$other] = $this->serviceHandlingCommandX($other);
        }
        $this->pulseHavingHeardFrom($client, $services);
        self::assertCount(1, $client->request('GET', '/flow')->filter('[data-flow-overview]'));

        $page = $client->request('GET', '/flow?' . http_build_query(['collapse' => $others]));

        self::assertCount(0, $page->filter('[data-flow-overview]'));
        $diagram = MermaidDiagram::fromPage($page);
        self::assertSame(1, $diagram->nodesOf('svc01'));
        self::assertGreaterThan(1, $diagram->nodesOf('orders'));
    }

    public function test_folding_channels_draws_an_asynchronous_channel_as_a_labelled_arrow_from_the_message_to_its_handler(): void
    {
        $client = $this->shippingWorkingAsynchronously();

        $diagram = MermaidDiagram::fromPage($client->request('GET', '/flow?fold=channels'));

        self::assertSame([], array_values(array_filter($diagram->nodes, fn (array $node) => $node['label'] === 'Channel<br/>shipping_work')));
        self::assertTrue($diagram->hasEdge('shipping', 'shipping.pack', 'async: shipping_work', 'shipping', '::pack', '==>'));
        self::assertTrue($diagram->hasEdge('shipping', 'shipping.label', 'async: shipping_work', 'shipping', '::label', '==>'));
        self::assertNotEmpty(array_filter($diagram->nodes, fn (array $node) => $node['label'] === 'Distributed Bus'));
    }

    public function test_channels_are_folded_and_shown_again_by_a_link(): void
    {
        $client = $this->shippingWorkingAsynchronously();

        self::assertSame('/flow?fold=channels', $client->request('GET', '/flow')->filter('[data-fold-channels]')->attr('href'));
        $folded = $client->request('GET', '/flow?fold=channels');
        self::assertCount(0, $folded->filter('[data-fold-channels]'));
        self::assertSame('/flow', $folded->filter('[data-unfold-channels]')->attr('href'));
    }

    public function test_finding_a_node_keeps_the_collapsed_services_the_folding_and_the_scope(): void
    {
        $client = $this->ordersAndBilling();
        $page = $client->request('GET', '/flow?collapse[]=billing&fold=channels&service=orders');

        $client->submit($page->filter('[data-flow-find]')->form(['find' => 'order.place']));

        self::assertResponseRedirects();
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertSame(['billing'], $query['collapse']);
        self::assertSame('channels', $query['fold']);
        self::assertSame('orders', $query['service']);
        self::assertStringStartsWith('orders:', $query['focus']);
    }

    public function test_choosing_services_or_a_scope_keeps_the_collapsed_services_and_the_folding(): void
    {
        $client = $this->ordersAndBilling();
        $page = $client->request('GET', '/flow?collapse[]=billing&fold=channels');

        foreach (['form.service-toggles', '[data-scope-filter]'] as $form) {
            $client->submit($page->filter($form)->form());

            self::assertSame(['billing'], $client->getRequest()->query->all('collapse'));
            self::assertSame('channels', $client->getRequest()->query->getString('fold'));
        }
    }

    public function test_collapsing_a_service_that_did_not_answer_is_refused_with_the_services_to_choose_from(): void
    {
        $client = $this->ordersAndBilling();

        $client->request('GET', '/flow?collapse[]=orders&collapse[]=ghost');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Cannot collapse &quot;ghost&quot;: it is not among the services that answered. Choose one of: billing, orders; or remove collapse[]=ghost from the address to keep the other services as they are.', (string) $client->getResponse()->getContent());
    }

    public function test_folding_anything_but_channels_is_refused_naming_what_can_be_folded(): void
    {
        $client = $this->shippingWorkingAsynchronously();

        $client->request('GET', '/flow?fold=handlers');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Cannot fold &quot;handlers&quot;: only channels can be folded into arrows. Use fold=channels, or remove the fold parameter to draw every channel.', (string) $client->getResponse()->getContent());
    }

    private function shippingWorkingAsynchronously(): KernelBrowser
    {
        $this->configurePulseWithServices('shipping');
        $client = $this->pulseWebClient();
        $handlers = new class () {
            #[Asynchronous('shipping_work')]
            #[CommandHandler('shipping.pack', endpointId: 'shipping.pack.handler')]
            public function pack(array $payload): void
            {
            }

            #[Asynchronous('shipping_work')]
            #[CommandHandler('shipping.label', endpointId: 'shipping.label.handler')]
            public function label(array $payload): void
            {
            }
        };
        $this->pulseHavingHeardFrom($client, ['shipping' => $this->distributedService('shipping', $handlers, DistributedServiceMap::initialize(), [SimpleMessageChannelBuilder::createQueueChannel('shipping_work')])]);

        return $client;
    }

    private function ordersAndBilling(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        return $client;
    }
}
