<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\Diagram;
use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class CatalogueNodePageTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_every_message_of_the_catalogue_links_to_its_own_page(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $link = $client->request('GET', '/catalogue')->filter('[data-service="billing"][data-message="invoice.create"] a[data-message-page]');
        $page = $client->request('GET', $link->attr('href'));

        self::assertResponseIsSuccessful();
        self::assertSame('command', $page->filter('[data-node-page]')->attr('data-node-kind'));
        self::assertStringContainsString('invoice.create', $page->filter('h1')->text());
    }

    public function test_a_command_page_says_which_handler_takes_it_and_which_service_sends_it(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $page = $this->nodePage($client, 'billing', 'invoice.create');

        self::assertMatchesRegularExpression('/^A command handled by \\S+::create in billing, which runs /', $page->filter('[data-node-sentence]')->text());
        self::assertStringContainsString('::create', $page->filter('[data-node-outgoing]')->text());
        self::assertStringContainsString('orders', $page->filter('[data-node-incoming]')->text());
        self::assertSame('invoice.create', $page->filter('[data-node-fact="Routing key"]')->text());
    }

    public function test_the_page_draws_the_node_with_its_neighbours_across_services_and_marks_it(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $diagram = Diagram::fromPage($this->nodePage($client, 'billing', 'invoice.create'));

        self::assertEqualsCanonicalizing(['orders', 'billing'], $diagram->services);
        $current = array_values(array_filter($diagram->nodes, static fn (array $node): bool => $node['current']));
        self::assertCount(1, $current);
        self::assertSame('invoice.create', $current[0]['name']);
        self::assertSame('billing', $current[0]['service']);
    }

    public function test_every_node_drawn_on_the_page_opens_its_own_page(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $diagram = Diagram::fromPage($this->nodePage($client, 'billing', 'invoice.create'));

        self::assertNotSame([], $diagram->nodes);
        foreach ($diagram->nodes as $node) {
            self::assertStringStartsWith('/catalogue/' . $node['service'] . '/node?id=', (string) $node['href']);
            $client->request('GET', (string) $node['href']);
            self::assertResponseIsSuccessful();
        }
    }

    public function test_a_handler_page_says_how_it_runs_and_links_the_message_it_handles(): void
    {
        $client = $this->pulseWithOrdersAndBilling();
        $handler = array_values(array_filter(Diagram::fromPage($this->nodePage($client, 'billing', 'invoice.create'))->nodes, static fn (array $node): bool => $node['kind'] === 'commandHandler' && str_contains($node['name'], '::create')))[0];

        $page = $client->request('GET', (string) $handler['href']);

        self::assertSame('commandHandler', $page->filter('[data-node-page]')->attr('data-node-kind'));
        self::assertStringContainsString('invoice.create', $page->filter('[data-node-incoming]')->text());
        self::assertCount(1, $page->filter('[data-node-fact="Runs"]'));
        self::assertCount(1, $page->filter('[data-node-fact="Method"]'));
    }

    public function test_the_page_links_to_the_whole_flow_of_the_node_on_the_flow_graph(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $href = $this->nodePage($client, 'billing', 'invoice.create')->filter('a[data-node-flow]')->attr('href');
        $focused = Diagram::fromPage($client->request('GET', $href));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $focused->focused());
        self::assertSame('invoice.create', $focused->nodes[$focused->focused()[0]]['name']);
    }

    public function test_the_followed_node_on_the_flow_graph_links_back_to_its_page(): void
    {
        $client = $this->pulseWithOrdersAndBilling();
        $flow = $this->nodePage($client, 'billing', 'invoice.create')->filter('a[data-node-flow]')->attr('href');

        $back = $client->request('GET', $flow)->filter('a[data-focus-page]')->attr('href');

        self::assertSame('invoice.create', trim($client->request('GET', $back)->filter('[data-node-page] .mono')->text()));
    }

    public function test_a_message_that_starts_no_stored_flow_says_why_it_has_no_numbers(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $page = $this->nodePage($client, 'billing', 'invoice.create');

        self::assertCount(0, $page->filter('[data-node-numbers]'));
        self::assertStringContainsString('per flow type', $page->filter('[data-node-no-numbers]')->text());
    }

    public function test_an_unknown_node_is_refused_naming_the_way_out(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $client->request('GET', '/catalogue/billing/node?id=message:never.declared');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Search the catalogue for the name', (string) $client->getResponse()->getContent());
    }

    public function test_an_unknown_service_is_refused_naming_the_services_that_answered(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $client->request('GET', '/catalogue/shipping/node?id=message:invoice.create');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('billing, orders', (string) $client->getResponse()->getContent());
    }

    public function test_a_page_without_a_node_id_is_refused_with_an_example(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $client->request('GET', '/catalogue/billing/node');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('?id=message:order.place', (string) $client->getResponse()->getContent());
    }

    public function test_search_lists_every_kind_of_node_whose_name_contains_the_text(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $page = $client->request('GET', '/search?q=invoice');

        self::assertResponseIsSuccessful();
        $kinds = array_unique($page->filter('[data-search-result] [data-kind-chip]')->each(static fn (Crawler $chip): string => $chip->attr('data-kind-chip')));
        self::assertContains('command', $kinds);
        self::assertContains('event', $kinds);
        self::assertContains('commandHandler', $kinds);
        self::assertEqualsCanonicalizing(['orders', 'billing'], array_values(array_unique($page->filter('[data-search-result]')->each(static fn (Crawler $row): string => $row->attr('data-service')))));
    }

    public function test_search_with_one_match_opens_its_page(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $client->request('GET', '/search?q=ORDER.PLACE');

        self::assertResponseRedirects();
        $page = $client->followRedirect();
        self::assertSame('order.place', trim($page->filter('[data-node-page] .mono')->text()));
    }

    public function test_search_matches_every_word_in_any_order(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $page = $client->request('GET', '/search?q=created+invoice');

        self::assertGreaterThan(0, $page->filter('[data-search-result]')->count());
    }

    public function test_an_event_page_names_the_subscriber_in_the_other_service(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $page = $this->nodePage($client, 'billing', 'invoice.created');

        self::assertStringContainsString('in orders', $page->filter('[data-node-sentence]')->text());
        self::assertStringContainsString('handles it in orders', $page->filter('[data-node-outgoing]')->text());
    }

    public function test_search_without_a_text_says_what_to_type(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $page = $client->request('GET', '/search');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('routing key', $page->filter('[data-empty]')->text());
        self::assertCount(0, $page->filter('[data-search-result]'));
    }

    public function test_search_before_any_service_answered_says_names_appear_after_the_first_answer(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseWebClient();

        $page = $client->request('GET', '/search?q=order');

        self::assertResponseIsSuccessful();
        self::assertSame('waiting', $page->filter('[data-empty]')->attr('data-empty'));
    }

    public function test_a_handler_on_an_asynchronous_channel_says_which_channel_it_runs_on_and_the_channel_page_lists_it(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->serviceHandlingAsynchronousCommand('orders')]);
        $client->request('GET', '/search?q=work.do.handler');

        $page = $client->followRedirect();
        $channel = $page->filter('[data-node-incoming] a')->reduce(static fn (Crawler $link): bool => str_contains((string) $link->attr('href'), 'id=channel'))->first();

        self::assertStringContainsString('asynchronously, on channel', $page->filter('[data-node-fact="Runs"]')->text());
        $channelPage = $client->request('GET', $channel->attr('href'));
        self::assertSame('channel', $channelPage->filter('[data-node-page]')->attr('data-node-kind'));
        self::assertStringContainsString('1 handler runs from it', $channelPage->filter('[data-node-sentence]')->text());
        self::assertStringContainsString('::handle', $channelPage->filter('[data-node-outgoing]')->text());
    }

    public function test_search_without_a_match_says_how_it_matches(): void
    {
        $client = $this->pulseWithOrdersAndBilling();

        $page = $client->request('GET', '/search?q=never.declared');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ignoring case', $page->filter('[data-empty]')->text());
    }

    private function pulseWithOrdersAndBilling(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        return $client;
    }

    private function nodePage(KernelBrowser $client, string $service, string $routingKey): Crawler
    {
        $page = $client->request('GET', '/catalogue/' . $service . '/node?id=' . rawurlencode('message:' . $routingKey));
        self::assertResponseIsSuccessful();

        return $page;
    }
}
