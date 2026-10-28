<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Interceptor\MethodInvocation;
use App\Tests\Support\MermaidDiagram;
use App\Tests\Support\ReleasingWebTestCase;
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
            $labels[$node['service']][] = $node['label'];
        }
        ksort($labels);

        return array_map(function (array $serviceLabels): array {
            sort($serviceLabels);

            return $serviceLabels;
        }, $labels);
    }
}
