<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\MermaidDiagram;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Interceptor\MethodInvocation;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class FlowGraphFocusTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_following_a_handler_shows_what_led_to_it_and_what_it_causes_across_services(): void
    {
        $client = $this->ordersAndBilling();

        $focused = $this->follow($client, $client->request('GET', '/flow'), 'billing', '::create');

        self::assertSame([
            'billing' => ['CommandHandler<br/>class@anonymous::create', 'Distributed Bus', 'invoice.create', 'invoice.created'],
            'orders' => ['CommandHandler<br/>class@anonymous::place', 'EventHandler<br/>class@anonymous::whenInvoiceCreated', 'invoice.create', 'invoice.created', 'order.place'],
        ], $this->labelsByService(MermaidDiagram::fromPage($focused)));
    }

    public function test_the_followed_node_is_marked_and_the_steps_before_and_after_it_are_listed_as_links_to_follow(): void
    {
        $client = $this->ordersAndBilling();

        $focused = $this->follow($client, $client->request('GET', '/flow'), 'billing', '::create');

        self::assertStringContainsString('class@anonymous::create', $focused->filter('[data-focus] h2')->text());
        $diagram = MermaidDiagram::fromPage($focused);
        $marked = array_values(array_filter($diagram->lines, fn (string $line) => str_starts_with($line, '    class ') && str_ends_with($line, ' focused')));
        self::assertCount(1, $marked);
        self::assertSame('CommandHandler<br/>class@anonymous::create', $diagram->nodes[explode(' ', $marked[0])[5]]['label']);
        self::assertContains('    classDef focused stroke-width:4px', $diagram->lines);
        self::assertSame(['order.place', 'class@anonymous::place', 'invoice.create', 'Distributed Bus', 'invoice.create'], $focused->filter('[data-focus-upstream] a')->each(fn (Crawler $link) => $link->text()));
        self::assertSame(['invoice.created', 'Distributed Bus', 'invoice.created', 'class@anonymous::whenInvoiceCreated'], $focused->filter('[data-focus-downstream] a')->each(fn (Crawler $link) => $link->text()));
        $refocused = $client->request('GET', $focused->filter('[data-focus-upstream] a')->first()->attr('href'));
        self::assertStringContainsString('order.place', $refocused->filter('[data-focus] h2')->text());
    }

    public function test_handlers_sharing_an_asynchronous_channel_or_an_interceptor_are_not_part_of_each_others_flow(): void
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
        $interceptor = new class () {
            #[Around(pointcut: CommandHandler::class)]
            public function measure(MethodInvocation $invocation): mixed
            {
                return $invocation->proceed();
            }
        };
        $this->pulseHavingHeardFrom($client, ['shipping' => $this->distributedService('shipping', $handlers, DistributedServiceMap::initialize(), [SimpleMessageChannelBuilder::createQueueChannel('shipping_work')], [$interceptor])]);

        $focused = $this->follow($client, $client->request('GET', '/flow'), 'shipping', '::pack');

        self::assertSame(['shipping' => ['Channel<br/>shipping_work', 'CommandHandler<br/>class@anonymous::pack', 'shipping.pack']], $this->labelsByService(MermaidDiagram::fromPage($focused)));
    }

    public function test_following_a_distributed_bus_shows_only_what_it_receives_and_what_it_sends_to(): void
    {
        $client = $this->ordersAndBilling();

        $focused = $this->follow($client, $client->request('GET', '/flow'), 'billing', 'Distributed Bus');

        self::assertSame(['billing' => ['Channel<br/>orders', 'Channel<br/>pulse', 'Distributed Bus', 'invoice.create', 'invoice.created']], $this->labelsByService(MermaidDiagram::fromPage($focused)));
    }

    public function test_following_a_channel_shows_only_the_handlers_it_feeds(): void
    {
        $this->configurePulseWithServices('shipping');
        $client = $this->pulseWebClient();
        $handlers = new class () {
            #[Asynchronous('shipping_work')]
            #[CommandHandler('shipping.pack', endpointId: 'shipping.pack.handler', outputChannelName: 'shipping.packed')]
            public function pack(array $payload): array
            {
                return $payload;
            }

            #[InternalHandler('shipping.packed', endpointId: 'shipping.packed.handler')]
            public function packed(array $payload): void
            {
            }
        };
        $this->pulseHavingHeardFrom($client, ['shipping' => $this->distributedService('shipping', $handlers, DistributedServiceMap::initialize(), [SimpleMessageChannelBuilder::createQueueChannel('shipping_work')])]);

        $focused = $this->follow($client, $client->request('GET', '/flow'), 'shipping', 'shipping_work');

        self::assertSame(['shipping' => ['Channel<br/>shipping_work', 'CommandHandler<br/>class@anonymous::pack']], $this->labelsByService(MermaidDiagram::fromPage($focused)));
    }

    public function test_following_a_node_of_a_service_that_did_not_answer_is_refused_with_the_services_to_choose_from(): void
    {
        $client = $this->ordersAndBilling();

        $client->request('GET', '/flow?focus=ghost:command:x');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Cannot follow "ghost:command:x": service "ghost" is not among the services that answered. Choose one of: billing, orders; type the name of the node into Find on the flow graph; or remove the focus parameter to see every service.', (string) $client->getResponse()->getContent());
    }

    public function test_following_a_node_the_service_no_longer_reports_is_refused_pointing_to_find(): void
    {
        $client = $this->ordersAndBilling();

        $client->request('GET', '/flow?focus=billing:command:gone');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Cannot follow "billing:command:gone": billing reports no node "command:gone", so its flow may have changed since the link was made. Type the name of the node into Find on the flow graph, or remove the focus parameter to see every service.', (string) $client->getResponse()->getContent());
    }

    public function test_finding_a_name_that_matches_one_node_follows_it(): void
    {
        $client = $this->ordersAndBilling();

        $client->request('GET', '/flow?find=ORDER.PLACE');

        self::assertResponseRedirects();
        $focused = $client->followRedirect();
        self::assertStringContainsString('order.place', $focused->filter('[data-focus] h2')->text());
        self::assertStringContainsString('orders', $focused->filter('[data-focus] h2')->text());
    }

    public function test_finding_the_exact_name_of_one_node_follows_it_even_when_other_names_contain_it(): void
    {
        $this->configurePulseWithServices('shipping');
        $client = $this->pulseWebClient();
        $handlers = new class () {
            #[CommandHandler('shipping.pack', endpointId: 'shipping.pack.handler')]
            public function pack(array $payload): void
            {
            }

            #[CommandHandler('shipping.pack.later', endpointId: 'shipping.pack.later.handler')]
            public function packLater(array $payload): void
            {
            }
        };
        $this->pulseHavingHeardFrom($client, ['shipping' => $this->distributedService('shipping', $handlers, DistributedServiceMap::initialize())]);

        $client->request('GET', '/flow?find=Shipping.Pack');

        self::assertResponseRedirects();
        self::assertStringContainsString('shipping.pack', $client->followRedirect()->filter('[data-focus] h2')->text());
        self::assertStringNotContainsString('later', $client->getCrawler()->filter('[data-focus] h2')->text());
    }

    public function test_finding_the_exact_name_of_several_nodes_lists_only_those(): void
    {
        $client = $this->ordersAndBilling();

        $found = $client->request('GET', '/flow?find=invoice.create');

        self::assertSame(['invoice.create command · billing', 'invoice.create command · orders'], $found->filter('[data-find-results] li')->each(fn (Crawler $item) => $item->text()));
    }

    public function test_finding_a_name_that_matches_several_nodes_lists_them_to_follow(): void
    {
        $client = $this->ordersAndBilling();

        $found = $client->request('GET', '/flow?find=invoice.created');

        self::assertResponseIsSuccessful();
        self::assertSame(['invoice.created event · billing', 'invoice.created event · orders'], $found->filter('[data-find-results] li')->each(fn (Crawler $item) => $item->text()));
        $followed = $client->request('GET', $found->filter('[data-find-results] a')->first()->attr('href'));
        self::assertStringContainsString('billing', $followed->filter('[data-focus] h2')->text());
    }

    public function test_finding_a_name_that_matches_nothing_says_so_and_keeps_what_was_typed(): void
    {
        $client = $this->ordersAndBilling();

        $found = $client->request('GET', '/flow?find=shipment');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No node matches "shipment"', $found->filter('[data-find-none]')->text());
        self::assertSame('shipment', $found->filter('[data-flow-find] input[name="find"]')->attr('value'));
    }

    public function test_the_find_box_is_labelled_and_suggests_the_names_of_the_nodes(): void
    {
        $client = $this->ordersAndBilling();

        $page = $client->request('GET', '/flow');

        $input = $page->filter('[data-flow-find] input[name="find"]');
        self::assertSame('Find a node', $page->filter('[data-flow-find] label[for="' . $input->attr('id') . '"]')->text());
        $suggestions = $page->filter('datalist#' . $input->attr('list') . ' option')->each(fn (Crawler $option) => $option->attr('value'));
        self::assertContains('order.place', $suggestions);
        self::assertContains('class@anonymous::create', $suggestions);
        self::assertSame(count(array_unique($suggestions)), count($suggestions));
    }

    private function ordersAndBilling(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        return $client;
    }

    private function follow(KernelBrowser $client, Crawler $page, string $service, string $titleEnding): Crawler
    {
        $nodes = json_decode($page->filter('pre.mermaid')->attr('data-graph-nodes'), true, flags: JSON_THROW_ON_ERROR);
        $matching = array_values(array_filter($nodes, fn (array $node) => $node['service'] === $service && str_ends_with($node['title'], $titleEnding)));
        self::assertCount(1, $matching);

        $focused = $client->request('GET', $matching[0]['href']);
        self::assertResponseIsSuccessful();

        return $focused;
    }

    /**
     * @return array<string, list<string>>
     */
    private function labelsByService(MermaidDiagram $diagram): array
    {
        $labels = [];
        foreach ($diagram->nodes as $node) {
            $labels[$node['service']][] = (string) preg_replace('/_\d+$/', '', $node['label']);
        }
        ksort($labels);

        return array_map(function (array $serviceLabels): array {
            sort($serviceLabels);

            return $serviceLabels;
        }, $labels);
    }
}
