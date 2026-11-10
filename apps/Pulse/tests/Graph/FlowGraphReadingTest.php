<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class FlowGraphReadingTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_the_page_opens_with_a_verdict_naming_the_services_their_message_types_and_what_crosses_the_distributed_bus(): void
    {
        $client = $this->ordersAndBilling();

        $crawler = $client->request('GET', '/flow');

        self::assertResponseIsSuccessful();
        $verdict = $crawler->filter('main [data-verdict]');
        self::assertCount(1, $verdict);
        self::assertSame('ok', $verdict->attr('data-verdict'));
        $sentence = $verdict->filter('[data-verdict-sentence]')->text();
        self::assertMatchesRegularExpression('/^(orders and billing|billing and orders) know \d+ message types \(\d+ commands?, \d+ events?, \d+ quer(y|ies)\); 1 command and 1 event cross the Distributed Bus\.$/', $sentence);
        $html = (string) $client->getResponse()->getContent();
        self::assertLessThan(strpos($html, '<pre class="mermaid"'), strpos($html, 'data-verdict='));
        self::assertCount(0, $crawler->filter('[data-problem]'));
        self::assertStringContainsString('every command and query sent has a handler', $crawler->filter('[data-flow-checked]')->text());
    }

    public function test_a_reading_guide_explains_the_shapes_and_arrows_with_concept_hints(): void
    {
        $client = $this->ordersAndBilling();

        $crawler = $client->request('GET', '/flow');

        $reading = $crawler->filter('[data-flow-reading]');
        self::assertCount(1, $reading);
        foreach (['command', 'event', 'query', 'distributed-bus'] as $term) {
            self::assertCount(1, $reading->filter('[data-concept="' . $term . '"]'), $term);
        }
        foreach (['inferred-arrow', 'asynchronous-arrow'] as $term) {
            self::assertCount(1, $crawler->filter('[data-graph-legend] [data-concept="' . $term . '"]'), $term);
        }
    }

    public function test_the_legend_and_the_service_toggles_start_collapsed_with_a_summary_that_counts(): void
    {
        $client = $this->ordersAndBilling();

        $crawler = $client->request('GET', '/flow');

        $legend = $crawler->filter('details[data-graph-legend]');
        self::assertNull($legend->attr('open'));
        self::assertMatchesRegularExpression('/\d/', $legend->filter('summary')->text());
        $toggles = $crawler->filter('details[data-flow-services]');
        self::assertNull($toggles->attr('open'));
        self::assertSame('Services', $toggles->filter('summary .section-title')->text());
        self::assertSame('2 of 2 shown', $toggles->filter('summary [data-summary-state]')->text());
        self::assertCount(1, $toggles->filter('form.service-toggles'));
        self::assertCount(0, $crawler->filter('main details details'));
    }

    public function test_the_page_has_breadcrumbs_that_keep_the_scoped_service(): void
    {
        $client = $this->ordersAndBilling();

        $all = $client->request('GET', '/flow');
        $scoped = $client->request('GET', '/flow?service=orders');

        self::assertSame(['Home', 'Flow graph'], $all->filter('[data-crumbs] li')->each(static fn (Crawler $crumb): string => $crumb->text()));
        self::assertSame(['Home', 'Services', 'orders', 'Flow graph'], $scoped->filter('[data-crumbs] li')->each(static fn (Crawler $crumb): string => $crumb->text()));
    }

    public function test_a_command_sent_where_no_handler_takes_it_is_a_problem_with_a_link_to_follow_it(): void
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
        };
        $this->pulseHavingHeardFrom($client, ['orders' => $this->distributedService('orders', $orders, DistributedServiceMap::initialize())]);

        $crawler = $client->request('GET', '/flow');

        self::assertSame('info', $crawler->filter('main [data-verdict]')->attr('data-verdict'));
        $problem = $crawler->filter('details[data-flow-doubts] [data-problem="unhandled-message"]');
        self::assertSame('info', $problem->attr('data-problem-severity'));
        self::assertCount(1, $problem);
        self::assertSame('orders sends 1 command that no handler in orders takes: order.archive.', $problem->filter('[data-problem-text]')->text());
        self::assertStringContainsString('inferred from the source code of', $problem->filter('[data-problem-evidence]')->text());
        self::assertStringStartsWith('/flow?focus=orders:', (string) $problem->filter('[data-problem-action]')->attr('href'));
        self::assertSame('Follow order.archive', $problem->filter('[data-problem-action]')->text());
    }

    public function test_a_message_received_over_the_distributed_bus_that_no_service_is_seen_sending_says_why_the_arrow_is_missing(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingAlsoVoidingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        self::assertSame('info', $crawler->filter('main [data-verdict]')->attr('data-verdict'));
        $problem = $crawler->filter('[data-problem="unknown-sender"]');
        self::assertCount(1, $problem);
        self::assertSame('billing receives 1 message over the Distributed Bus that no service is seen sending: invoice.void.', $problem->filter('[data-problem-text]')->text());
        self::assertStringContainsString('routing key in a variable', $problem->filter('[data-problem-evidence]')->text());
        self::assertStringStartsWith('/flow?focus=billing:', (string) $problem->filter('[data-problem-action]')->attr('href'));
    }

    public function test_a_send_whose_routing_key_is_computed_at_runtime_says_its_arrow_stops_at_the_distributed_bus(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $orders = new class () {
            public function __construct(private ?DistributedBus $distributedBus = null)
            {
            }

            #[CommandHandler('order.place', endpointId: 'order.place.handler')]
            public function place(array $payload): void
            {
                $routingKey = $payload['routingKey'];
                $this->distributedBus->sendCommand('billing', $routingKey, '{}');
            }
        };
        $this->pulseHavingHeardFrom($client, ['orders' => $this->distributedService('orders', $orders, DistributedServiceMap::initialize()->withCommandMapping('billing', $this->serviceChannels['billing'])), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/flow');

        $problem = $crawler->filter('[data-problem="runtime-routing-key"]');
        self::assertCount(1, $problem);
        self::assertMatchesRegularExpression('/^orders sends 1 message to billing with a routing key computed at runtime, so its arrow stops at the Distributed Bus: from \S+::place\.$/', $problem->filter('[data-problem-text]')->text());
        self::assertStringStartsWith('/flow?focus=orders:', (string) $problem->filter('[data-problem-action]')->attr('href'));
    }

    public function test_doubts_of_the_source_code_inference_wait_collapsed_behind_one_summary_with_their_count(): void
    {
        $names = ['s1', 's2', 's3', 's4'];
        $this->configurePulseWithServices(...$names);
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, array_combine($names, array_map(fn (string $name) => $this->serviceHandlingCommandX($name), $names)));

        $crawler = $client->request('GET', '/flow');

        self::assertSame('info', $crawler->filter('main [data-verdict]')->attr('data-verdict'));
        self::assertCount(0, $crawler->filter('[data-flow-findings] > [data-problem]'));
        $doubts = $crawler->filter('details[data-flow-doubts]');
        self::assertNull($doubts->attr('open'));
        self::assertSame('4 things Pulse could not confirm from the source code · info', $doubts->filter('summary')->text());
        self::assertCount(4, $doubts->filter('[data-problem]'));
        self::assertCount(0, $crawler->filter('details details'));
    }

    private function ordersAndBilling(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        return $client;
    }

    private function billingAlsoVoidingInvoices(): FlowTestSupport
    {
        $billing = new class () {
            public function __construct(private ?DistributedBus $distributedBus = null)
            {
            }

            #[Distributed]
            #[CommandHandler('invoice.create', endpointId: 'invoice.create.handler')]
            public function create(array $payload): void
            {
                $this->distributedBus->publishEvent('invoice.created', '{}');
            }

            #[Distributed]
            #[CommandHandler('invoice.void', endpointId: 'invoice.void.handler')]
            public function void(array $payload): void
            {
            }
        };

        return $this->distributedService('billing', $billing, DistributedServiceMap::initialize()->withEventMapping($this->serviceChannels['orders'], ['invoice.*']));
    }
}
