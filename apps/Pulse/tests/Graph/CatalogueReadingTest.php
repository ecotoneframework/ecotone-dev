<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\Gateway\CommandBus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class CatalogueReadingTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_the_verdict_says_how_many_messages_of_each_kind_the_services_know(): void
    {
        $crawler = $this->pulseKnowingOrders()->request('GET', '/catalogue');

        self::assertCount(1, $crawler->filter('main [data-verdict]'));
        self::assertSame('5 messages in 1 service: 3 commands, 1 event and 1 query.', $crawler->filter('main [data-verdict-sentence]')->text());
        self::assertSame('3 commands · 1 event · 1 query', $crawler->filter('[data-catalogue-service="orders"] [data-catalogue-service-counts]')->text());
    }

    public function test_the_catalogue_counts_a_message_known_to_two_services_once_as_the_flow_graph_does(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $catalogue = $client->request('GET', '/catalogue')->filter('main [data-verdict-sentence]')->text();
        $flow = $client->request('GET', '/flow')->filter('main [data-verdict-sentence]')->text();

        self::assertSame(1, preg_match('/know (\d+) message types/', $flow, $known));
        self::assertStringStartsWith($known[1] . ' messages in 2 services: ', $catalogue);
        self::assertMatchesRegularExpression('/; \d+ (is|are) listed in both services\.$/', $catalogue);
    }

    public function test_the_catalogue_has_breadcrumbs_that_keep_the_service(): void
    {
        $client = $this->pulseKnowingOrders();

        $all = $client->request('GET', '/catalogue');
        $scoped = $client->request('GET', '/catalogue?service=orders');

        self::assertSame(['Home', 'Catalogue'], $all->filter('[data-crumbs] li')->each(static fn (Crawler $crumb): string => $crumb->text()));
        self::assertSame(['Home', 'Services', 'orders', 'Catalogue'], $scoped->filter('[data-crumbs] li')->each(static fn (Crawler $crumb): string => $crumb->text()));
    }

    public function test_groups_stay_collapsed_behind_their_counts_until_a_search_or_a_kind_is_chosen(): void
    {
        $client = $this->pulseKnowingOrders();

        $browsing = $client->request('GET', '/catalogue')->filter('[data-group="services"][data-service="orders"] details[data-section]');
        $searching = $client->request('GET', '/catalogue?q=order')->filter('[data-group="services"][data-service="orders"] details[data-section]');
        $filtering = $client->request('GET', '/catalogue?kind=event')->filter('[data-group="services"][data-service="orders"] details[data-section]');

        self::assertNull($browsing->attr('open'));
        self::assertSame('3 commands · 1 event · 1 query', $browsing->filter('[data-summary-state]')->text());
        self::assertNotNull($searching->attr('open'));
        self::assertNotNull($filtering->attr('open'));
    }

    public function test_messages_are_listed_under_their_kind_with_the_handlers_that_take_them(): void
    {
        $crawler = $this->pulseKnowingOrders()->request('GET', '/catalogue');

        $group = $crawler->filter('[data-group="services"][data-service="orders"]');
        self::assertSame(['order.cancel', 'order.place'], array_values(array_intersect(['order.cancel', 'order.place'], $group->filter('[data-catalogue-kind="command"] [data-message]')->each(static fn (Crawler $row): string => (string) $row->attr('data-message')))));
        self::assertSame(['order.placed'], $group->filter('[data-catalogue-kind="event"] [data-message]')->each(static fn (Crawler $row): string => (string) $row->attr('data-message')));
        self::assertSame(['order.get'], $group->filter('[data-catalogue-kind="query"] [data-message]')->each(static fn (Crawler $row): string => (string) $row->attr('data-message')));
        self::assertSame('command', $group->filter('[data-message="order.place"]')->attr('data-kind'));
        self::assertCount(2, $group->filter('[data-message="order.placed"] [data-subscriber]'));
        self::assertStringContainsString('2 subscribers', $group->filter('[data-message="order.placed"]')->text());
    }

    public function test_the_kind_filter_stays_in_the_url_and_lists_only_that_kind(): void
    {
        $crawler = $this->pulseKnowingOrders()->request('GET', '/catalogue?service=orders&q=order&kind=event');

        self::assertSame(['order.placed'], $crawler->filter('[data-message]')->each(static fn (Crawler $row): string => (string) $row->attr('data-message')));
        $chips = $crawler->filter('[data-catalogue-kinds] a');
        self::assertSame('event', $crawler->filter('[data-catalogue-kinds] a[aria-current]')->attr('data-catalogue-kind-filter'));
        self::assertSame('/catalogue?service=orders&q=order&kind=command', $chips->filter('[data-catalogue-kind-filter="command"]')->attr('href'));
        self::assertSame('/catalogue?service=orders&q=order', $chips->filter('[data-catalogue-kind-filter=""]')->attr('href'));
        self::assertSame('event', $crawler->filter('[data-catalogue-search] input[name="kind"]')->attr('value'));
        self::assertSame('event', $crawler->filter('[data-scope-filter] input[name="kind"]')->attr('value'));
    }

    public function test_each_message_links_to_where_the_flow_graph_follows_it(): void
    {
        $client = $this->pulseKnowingOrders();
        $crawler = $client->request('GET', '/catalogue');

        $link = $crawler->filter('[data-service="orders"][data-message="order.place"] a[data-message-flow]');
        self::assertStringStartsWith('/flow?focus=orders:', (string) $link->attr('href'));
        $flow = $client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('order.place', $flow->filter('[data-focus] h2')->text());
    }

    public function test_command_event_query_handler_and_distributed_bus_are_explained_where_they_first_appear(): void
    {
        $crawler = $this->pulseKnowingOrders()->request('GET', '/catalogue');

        foreach (['command', 'event', 'query', 'handler', 'distributed-bus'] as $term) {
            self::assertGreaterThan(0, $crawler->filter('main [data-catalogue-reading] [data-concept="' . $term . '"]')->count(), $term);
        }
    }

    public function test_the_search_help_is_a_hint_on_the_search_box(): void
    {
        $crawler = $this->pulseKnowingOrders()->request('GET', '/catalogue');

        self::assertStringContainsString('contains the whole text, ignoring case', $crawler->filter('[data-catalogue-search] [data-search-help]')->text());
    }

    public function test_a_message_known_only_from_handler_source_code_is_marked_inferred(): void
    {
        $crawler = $this->pulseKnowingOrders()->request('GET', '/catalogue');

        $inferred = $crawler->filter('[data-service="orders"][data-message="order.archive"]');
        self::assertSame('true', $inferred->attr('data-inferred'));
        self::assertCount(1, $inferred->filter('[data-concept="inferred-message"]'));
        self::assertNull($crawler->filter('[data-service="orders"][data-message="order.place"]')->attr('data-inferred'));
        self::assertStringContainsString('1 inferred from handler source code', $crawler->filter('main [data-verdict]')->text());
    }

    public function test_before_any_service_answers_the_verdict_is_unknown_with_its_reason(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseWebClient();

        $crawler = $client->request('GET', '/catalogue');

        self::assertSame('unknown', $crawler->filter('main [data-verdict]')->attr('data-verdict'));
        self::assertNotSame('', trim($crawler->filter('main [data-verdict] [data-status-reason]')->text()));
        self::assertCount(1, $crawler->filter('[data-empty="waiting"]'));
    }

    private function pulseKnowingOrders(): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseWebClient();
        $orders = new class () {
            public function __construct(private ?CommandBus $commandBus = null)
            {
            }

            #[CommandHandler('order.place', endpointId: 'order.place.handler')]
            public function place(array $payload): void
            {
                $this->commandBus->sendWithRouting('order.archive', []);
            }

            #[CommandHandler('order.cancel', endpointId: 'order.cancel.handler')]
            public function cancel(array $payload): void
            {
            }

            #[EventHandler('order.placed', endpointId: 'order.placed.audit')]
            public function audit(array $payload): void
            {
            }

            #[EventHandler('order.placed', endpointId: 'order.placed.mail')]
            public function mail(array $payload): void
            {
            }

            #[QueryHandler('order.get', endpointId: 'order.get.handler')]
            public function get(array $payload): array
            {
                return [];
            }
        };
        $this->pulseHavingHeardFrom($client, ['orders' => $this->distributedService('orders', $orders, DistributedServiceMap::initialize())]);

        return $client;
    }
}
