<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\Flow\FlowVersionsConfiguration;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class FlowComparePageTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    private string $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->location = sys_get_temp_dir() . '/pulse-flow-versions-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->location)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->location, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->location);
        }
        parent::tearDown();
    }

    public function test_the_flow_graph_links_to_comparing_the_versions_of_each_service(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersPlacingAndRefunding()]);

        $crawler = $client->request('GET', '/flow');

        self::assertSame('/flow/compare?service=orders', $crawler->filter('[data-compare-versions="orders"]')->attr('href'));
    }

    public function test_the_first_view_waits_for_the_service_to_list_its_versions(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersPlacingAndRefunding()]);

        $crawler = $client->request('GET', '/flow/compare?service=orders');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-waiting="orders"]'));
        self::assertCount(1, $crawler->filter('meta[http-equiv="refresh"]'));
    }

    public function test_the_latest_stored_version_is_compared_with_the_live_flow_by_default(): void
    {
        $this->configurePulseWithServices('orders');
        $this->ordersPlacingAndAuditing()->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);
        $orders = $this->ordersPlacingAndRefunding();
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $this->compared($client, $orders, '/flow/compare?service=orders');

        self::assertSame('1.0.0', $crawler->filter('select[name="from"] option[selected]')->attr('value'));
        self::assertSame('', $crawler->filter('select[name="to"] option[selected]')->attr('value'));
        self::assertSame('From version 1.0.0 to the live flow of orders: 3 added, 3 removed, 0 changed.', trim($crawler->filter('[data-compare-summary]')->text()));
        self::assertCount(0, $crawler->filter('meta[http-equiv="refresh"]'));
    }

    public function test_added_and_removed_nodes_are_marked_on_the_diagram_and_the_rest_is_not(): void
    {
        $crawler = $this->comparedWithStoredAuditingVersion();

        self::assertSame(['Command handler · added', 'Command · added'], $this->nodesMarked($crawler, 'added'));
        self::assertSame(['Event handler · removed', 'Event · removed'], $this->nodesMarked($crawler, 'removed'));
        self::assertCount(1, $crawler->filter('[data-diagram-node][data-change="added"] [data-diagram-label]')->reduce(fn (Crawler $label): bool => $label->text() === 'order.refund'));
        self::assertCount(1, $crawler->filter('[data-diagram-node][data-change="removed"] [data-diagram-label]')->reduce(fn (Crawler $label): bool => $label->text() === 'order.placed'));
        self::assertSame([], $this->nodesMarked($crawler, 'changed'));
        self::assertCount(1, $crawler->filter('[data-diagram-node]:not([data-change]) [data-diagram-label]')->reduce(fn (Crawler $label): bool => $label->text() === 'order.place'));
    }

    public function test_added_and_removed_arrows_are_marked_on_the_diagram(): void
    {
        $crawler = $this->comparedWithStoredAuditingVersion();

        self::assertCount(1, $crawler->filter('[data-diagram-edge][data-change="added"]'));
        self::assertStringStartsWith('added order.refund → ', trim($crawler->filter('[data-diagram-edge][data-change="added"]')->text()));
        self::assertCount(1, $crawler->filter('[data-diagram-edge][data-change="removed"]'));
        self::assertStringStartsWith('removed order.placed → ', trim($crawler->filter('[data-diagram-edge][data-change="removed"]')->text()));
    }

    public function test_every_change_is_listed_under_the_diagram_pointing_at_its_node(): void
    {
        $crawler = $this->comparedWithStoredAuditingVersion();

        self::assertSame(['added', 'added', 'added', 'removed', 'removed', 'removed'], $crawler->filter('[data-compare-change]')->each(fn (Crawler $change): string => (string) $change->attr('data-compare-change')));
        $pointed = $crawler->filter('[data-compare-change="added"] [data-compare-show]')->first()->attr('data-compare-show');
        self::assertCount(1, $crawler->filter(sprintf('[data-diagram-node="%s"][data-change="added"]', $pointed)));
    }

    public function test_a_node_whose_attributes_differ_is_marked_changed_with_what_differs(): void
    {
        $this->configurePulseWithServices('orders');
        $orders = $this->ordersPlacingAndRefunding();
        $orders->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);
        file_put_contents($this->location . '/1.0.0.json', str_replace('order.place.handler', 'order.place.before', (string) file_get_contents($this->location . '/1.0.0.json')));
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $this->compared($client, $orders, '/flow/compare?service=orders');

        self::assertSame(['Command handler · changed'], $this->nodesMarked($crawler, 'changed'));
        self::assertStringContainsString('endpointId: "order.place.before" → "order.place.handler"', $crawler->filter('[data-compare-change="changed"]')->text());
    }

    public function test_two_stored_versions_are_compared_with_each_other(): void
    {
        $this->configurePulseWithServices('orders');
        $this->ordersPlacingAndAuditing()->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);
        $orders = $this->ordersPlacingAndRefunding();
        $orders->runConsoleCommand('ecotone:flow:store', ['label' => '1.1.0']);
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $this->compared($client, $orders, '/flow/compare?service=orders&from=1.1.0&to=1.0.0');

        self::assertSame('From version 1.1.0 to version 1.0.0 of orders: 3 added, 3 removed, 0 changed.', trim($crawler->filter('[data-compare-summary]')->text()));
        self::assertSame(['Event handler · added', 'Event · added'], $this->nodesMarked($crawler, 'added'));
    }

    public function test_identical_versions_say_that_nothing_changed_and_draw_the_flow_unmarked(): void
    {
        $this->configurePulseWithServices('orders');
        $orders = $this->ordersPlacingAndRefunding();
        $orders->runConsoleCommand('ecotone:flow:store', ['label' => '1.1.0']);
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $this->compared($client, $orders, '/flow/compare?service=orders');

        self::assertSame('From version 1.1.0 to the live flow of orders: nothing changed.', trim($crawler->filter('[data-compare-summary]')->text()));
        self::assertCount(0, $crawler->filter('[data-diagram-node][data-change]'));
        self::assertGreaterThan(0, $crawler->filter('[data-diagram-node]')->count());
    }

    public function test_a_service_without_a_location_is_told_how_to_configure_one(): void
    {
        $this->configurePulseWithServices('orders');
        $orders = $this->orders([]);
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $this->compared($client, $orders, '/flow/compare?service=orders');

        self::assertCount(1, $crawler->filter('[data-compare-not-configured="orders"]'));
        self::assertStringContainsString('FlowVersionsConfiguration::createWithLocation', $crawler->filter('[data-compare-not-configured] pre')->text());
        self::assertCount(0, $crawler->filter('[data-diagram]'));
    }

    public function test_a_service_with_no_stored_version_is_told_how_to_store_the_first_one(): void
    {
        $this->configurePulseWithServices('orders');
        $orders = $this->ordersPlacingAndRefunding();
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $this->compared($client, $orders, '/flow/compare?service=orders');

        self::assertCount(1, $crawler->filter('[data-compare-no-versions="orders"]'));
        self::assertStringContainsString('ecotone:flow:store', $crawler->filter('[data-compare-no-versions]')->text());
    }

    public function test_a_version_the_service_does_not_keep_shows_the_services_refusal(): void
    {
        $this->configurePulseWithServices('orders');
        $orders = $this->ordersPlacingAndRefunding();
        $orders->runConsoleCommand('ecotone:flow:store', ['label' => '1.1.0']);
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $this->compared($client, $orders, '/flow/compare?service=orders&from=0.1.0');

        self::assertStringContainsString('Flow version `0.1.0` is not stored in', $crawler->filter('[data-compare-refused]')->text());
        self::assertCount(0, $crawler->filter('[data-diagram]'));
    }

    public function test_a_service_that_never_lists_its_versions_is_said_to_predate_them_or_to_be_down(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersPlacingAndRefunding()]);

        $client->request('GET', '/flow/compare?service=orders');
        $this->advanceTimeBy(11);
        $crawler = $client->request('GET', '/flow/compare?service=orders');

        self::assertSame(
            'orders is not answering for its flow versions. A service answers them from the Ecotone version that introduced flow versions on: upgrade Ecotone in orders, or check that it is running and consuming its channel.',
            trim($crawler->filter('[data-compare-refused]')->text()),
        );
        self::assertCount(0, $crawler->filter('meta[http-equiv="refresh"]'));
    }

    public function test_a_service_that_listed_its_versions_and_then_stopped_answering_is_reported_for_the_version(): void
    {
        $this->configurePulseWithServices('orders');
        $orders = $this->ordersPlacingAndRefunding();
        $orders->runConsoleCommand('ecotone:flow:store', ['label' => '1.1.0']);
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);
        $client->request('GET', '/flow/compare?service=orders');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());

        $waiting = $client->request('GET', '/flow/compare?service=orders');
        $this->advanceTimeBy(11);
        $crawler = $client->request('GET', '/flow/compare?service=orders');

        self::assertSame('Waiting for orders to answer with version 1.1.0…', trim($waiting->filter('[data-waiting="orders"]')->text()));
        self::assertCount(1, $waiting->filter('meta[http-equiv="refresh"]'));
        self::assertSame('orders is not answering for this flow version. Check that the service is running and consuming its channel.', trim($crawler->filter('[data-compare-refused]')->text()));
        self::assertCount(0, $crawler->filter('[data-diagram]'));
    }

    public function test_a_version_whose_graph_pulse_cannot_read_says_so_instead_of_waiting(): void
    {
        $this->configurePulseWithServices('orders');
        $orders = $this->serviceAnsweringWithAnUnreadableVersion();
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $this->compared($client, $orders, '/flow/compare?service=orders');

        self::assertSame(
            'orders answered with version 1.0.0, but this Pulse cannot read its graph. Update Pulse to the Ecotone version the service runs, or store the version again with ecotone:flow:store 1.0.0 --replace.',
            trim($crawler->filter('[data-compare-refused]')->text()),
        );
        self::assertCount(0, $crawler->filter('[data-waiting]'));
    }

    public function test_comparing_a_version_with_itself_says_that_nothing_changed(): void
    {
        $this->configurePulseWithServices('orders');
        $orders = $this->ordersPlacingAndRefunding();
        $orders->runConsoleCommand('ecotone:flow:store', ['label' => '1.1.0']);
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $this->compared($client, $orders, '/flow/compare?service=orders&from=1.1.0&to=1.1.0');

        self::assertSame('From version 1.1.0 to version 1.1.0 of orders: nothing changed.', trim($crawler->filter('[data-compare-summary]')->text()));
    }

    public function test_with_several_services_and_none_chosen_the_page_asks_which_one_to_compare(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersPlacingAndRefunding(), 'billing' => $this->serviceHandlingCommandX('billing')]);

        $crawler = $client->request('GET', '/flow/compare');

        self::assertResponseIsSuccessful();
        self::assertSame(['/flow/compare?service=orders', '/flow/compare?service=billing'], $crawler->filter('[data-compare-service]')->each(fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function test_a_service_pulse_does_not_know_is_not_found(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersPlacingAndRefunding()]);

        $client->request('GET', '/flow/compare?service=unknown');

        self::assertResponseStatusCodeSame(404);
    }

    private function comparedWithStoredAuditingVersion(): Crawler
    {
        $this->configurePulseWithServices('orders');
        $this->ordersPlacingAndAuditing()->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);
        $orders = $this->ordersPlacingAndRefunding();
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        return $this->compared($client, $orders, '/flow/compare?service=orders');
    }

    private function compared(KernelBrowser $client, FlowTestSupport $orders, string $url): Crawler
    {
        $crawler = $client->request('GET', $url);
        for ($round = 0; $round < 3 && $crawler->filter('meta[http-equiv="refresh"]')->count() > 0; $round++) {
            $this->runService($orders, 'orders');
            $this->runPulseWorker($client->getKernel());
            $crawler = $client->request('GET', $url);
        }
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return list<string>
     */
    private function nodesMarked(Crawler $crawler, string $change): array
    {
        $nodes = $crawler->filter(sprintf('[data-diagram-node][data-change="%s"]', $change))->each(fn (Crawler $node): string => $node->filter('.dn-kind')->text());
        sort($nodes);

        return $nodes;
    }

    private function serviceAnsweringWithAnUnreadableVersion(): FlowTestSupport
    {
        $handler = new class () {
            #[Distributed]
            #[CommandHandler('ecotone.pulse.snapshot')]
            public function snapshot(string $request): string
            {
                return json_encode(['schemaVersion' => 1, 'serviceName' => 'orders', 'takenAt' => '2026-10-20T12:00:00+00:00', 'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]], 'flowGraph' => ['nodes' => [], 'edges' => []], 'topology' => ['serviceMaps' => []]], JSON_THROW_ON_ERROR);
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.flow_versions.list')]
            public function versions(string $request, #[Header('ecotone_pulse_query_id')] string $queryId): string
            {
                return json_encode(['queryId' => $queryId, 'configured' => true, 'versions' => [['label' => '1.0.0', 'storedAt' => '2026-10-20T12:00:00+00:00', 'nodes' => 0, 'edges' => 1]]], JSON_THROW_ON_ERROR);
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.flow_versions.version')]
            public function version(string $request, #[Header('ecotone_pulse_query_id')] string $queryId): string
            {
                return json_encode(['queryId' => $queryId, 'label' => '1.0.0', 'storedAt' => '2026-10-20T12:00:00+00:00', 'graph' => ['nodes' => [], 'edges' => [['from' => 'message:gone', 'to' => 'handler:gone', 'kind' => 'routedTo', 'attributes' => []]]]], JSON_THROW_ON_ERROR);
            }
        };

        return $this->serviceApplication('orders', [$handler::class], [$handler], false);
    }

    private function ordersPlacingAndAuditing(): FlowTestSupport
    {
        return $this->orders([FlowVersionsConfiguration::createWithLocation($this->location)], $this->auditing());
    }

    private function ordersPlacingAndRefunding(): FlowTestSupport
    {
        return $this->orders([FlowVersionsConfiguration::createWithLocation($this->location)], $this->refunding());
    }

    /**
     * @param array<object> $extensionObjects
     */
    private function orders(array $extensionObjects, object ...$otherHandlers): FlowTestSupport
    {
        return $this->distributedService('orders', $this->placing(), DistributedServiceMap::initialize(), $extensionObjects, array_values($otherHandlers));
    }

    private function placing(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'order.place.handler')]
            public function place(array $payload): void
            {
            }
        };
    }

    private function auditing(): object
    {
        return new class () {
            #[EventHandler('order.placed', endpointId: 'order.placed.audit')]
            public function record(array $payload): void
            {
            }
        };
    }

    private function refunding(): object
    {
        return new class () {
            #[CommandHandler('order.refund', endpointId: 'order.refund.handler')]
            public function refund(array $payload): void
            {
            }
        };
    }
}
