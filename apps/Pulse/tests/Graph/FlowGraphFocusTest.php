<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
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
