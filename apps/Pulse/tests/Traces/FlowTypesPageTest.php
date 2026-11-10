<?php

declare(strict_types=1);

namespace App\Tests\Traces;

use App\Tests\Support\ReleasingWebTestCase;
use App\Tests\Support\TracedServicesTrait;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class FlowTypesPageTest extends ReleasingWebTestCase
{
    use TracedServicesTrait;

    public function test_traces_page_lists_the_flow_types_of_a_service_with_their_statistics(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $orders->sendCommandWithRouting('order.place', ['orderId' => 1]);
        $orders->sendCommandWithRouting('order.place', ['orderId' => 2]);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $client->request('GET', '/traces');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-flow-type="order.place"][data-service="orders"]');
        self::assertCount(1, $row);
        self::assertSame('2', $row->filter('[data-stat="runs"]')->text());
        self::assertSame('0', $row->filter('[data-stat="runsWithErrors"]')->text());
        self::assertSame('0%', $row->filter('[data-stat="errorRate"]')->text());
        self::assertNotSame('', $row->filter('[data-stat="p50"]')->text());
        self::assertNotSame('', $row->filter('[data-stat="p95"]')->text());
        self::assertNotSame('', $row->filter('[data-stat="max"]')->text());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC$/', $row->filter('[data-stat="lastRun"]')->attr('title'));
    }

    public function test_traces_page_says_flow_tracking_is_not_enabled_for_a_service_without_it_and_shows_how_to_enable_it(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders(tracing: false);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $client->request('GET', '/traces');

        $notice = $crawler->filter('[data-tracing-disabled="orders"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('flow tracking is not enabled', $notice->text());
        self::assertStringContainsString('FlowTrackingConfiguration::createWithDefaults()', $notice->text());
        self::assertCount(0, $crawler->filter('[data-flow-type]'));
    }

    public function test_the_same_flow_type_in_two_services_is_listed_once_per_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $billing = $this->tracedBilling();
        $orders->sendCommandWithRouting('order.invoice', ['orderId' => 1]);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);
        $billing->run($this->serviceChannels['billing'], \Ecotone\Api\ExtensionObject\ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseHavingHeardFrom($client, ['billing' => $billing]);

        $crawler = $client->request('GET', '/traces');

        self::assertCount(1, $crawler->filter('[data-flow-type="order.invoice"][data-service="orders"]'));
        self::assertCount(1, $crawler->filter('[data-flow-type="invoice.create"][data-service="billing"]'));
    }

    public function test_a_trace_id_with_a_slash_is_refused_with_a_message_instead_of_being_ignored(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();

        $crawler = $client->request('GET', '/traces', ['traceId' => 'a/b']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"a/b"', $crawler->filter('[data-search-error]')->text());
        self::assertStringContainsString('cannot contain a slash', $crawler->filter('[data-search-error]')->text());
    }

    public function test_flow_types_with_errors_recorded_come_first_then_the_slowest_and_queries_last(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $work = $this->workingOrders();
        $this->runWork($work);
        $this->pulseHavingHeardFrom($client, ['orders' => $work]);

        $crawler = $client->request('GET', '/traces');

        self::assertSame(['work.fail', 'work.slow', 'work.fast', 'work.lookup'], $this->flowTypesOf($crawler, 'orders'));
        self::assertSame('query', $crawler->filter('[data-flow-type="work.lookup"] [data-flow-kind]')->attr('data-flow-kind'));
    }

    public function test_the_verdict_names_the_flow_types_with_errors_recorded_and_leads_to_their_traces(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $work = $this->workingOrders();
        $this->runWork($work);
        $this->pulseHavingHeardFrom($client, ['orders' => $work]);

        $crawler = $client->request('GET', '/traces');

        $verdict = $crawler->filter('[data-verdict]');
        self::assertSame('warning', $verdict->attr('data-verdict'));
        self::assertStringContainsString('1 flow type with errors recorded', $verdict->filter('[data-verdict-sentence]')->text());
        self::assertSame('/traces?service=orders', $verdict->filter('[data-verdict-sentence] a')->attr('href'));
        self::assertSame('/traces/orders/work.fail', $verdict->filter('[data-verdict-action]')->attr('href'));
        self::assertStringContainsString('work.fail', $verdict->filter('[data-verdict-action]')->text());
        self::assertLessThan(strpos($crawler->html(), 'data-service-section'), strpos($crawler->html(), 'data-verdict'));
    }

    public function test_the_verdict_leads_to_the_flow_type_with_the_most_errors_recorded_across_services(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedService('orders', $this->ordersFailingInsideTwoFlows(), DistributedServiceMap::initialize());
        $billing = $this->tracedService('billing', $this->billingFailingInsidePayment(), DistributedServiceMap::initialize());
        $orders->sendCommandWithRouting('order.first', ['id' => 1]);
        $orders->sendCommandWithRouting('order.second', ['id' => 1]);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $billing->sendCommandWithRouting('invoice.pay', ['id' => $attempt]);
        }
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $billing]);

        $crawler = $client->request('GET', '/traces');

        $verdict = $crawler->filter('[data-verdict]');
        self::assertSame('/traces/billing/invoice.pay', $verdict->filter('[data-verdict-action]')->attr('href'));
        self::assertSame('Traces of invoice.pay in billing', trim($verdict->filter('[data-verdict-action]')->text()));
        self::assertSame(['billing', 'orders'], array_slice($crawler->filter('[data-service-section]')->each(static fn (Crawler $section): string => (string) $section->attr('data-service-section')), 0, 2));
    }

    public function test_the_verdict_is_all_clear_when_no_flow_type_has_errors_recorded(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $billing = $this->tracedBilling();
        $shipping = $this->tracedShipping();
        $orders->sendCommandWithRouting('order.place', ['orderId' => 1]);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $billing, 'shipping' => $shipping]);

        $crawler = $client->request('GET', '/traces');

        self::assertSame('ok', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertStringContainsString('All clear', $crawler->filter('[data-verdict-sentence]')->text());
        self::assertStringContainsString('no errors recorded', $crawler->filter('[data-verdict-sentence]')->text());
        self::assertCount(0, $crawler->filter('[data-verdict-action]'));
    }

    public function test_only_the_five_most_urgent_flow_types_of_a_service_are_shown_and_the_rest_wait_behind_show_all(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $work = $this->workingOrders();
        $this->runWork($work);
        $work->sendCommandWithRouting('work.other', ['id' => 2]);
        $work->sendCommandWithRouting('work.idle', ['id' => 2]);
        $this->pulseHavingHeardFrom($client, ['orders' => $work]);

        $crawler = $client->request('GET', '/traces');

        $section = $crawler->filter('[data-service-section="orders"]');
        self::assertCount(6, $section->filter('[data-flow-type]'));
        self::assertCount(1, $section->filter('[data-more-flow-types] [data-flow-type]'));
        self::assertSame('work.lookup', $section->filter('[data-more-flow-types] [data-flow-type]')->attr('data-flow-type'));
        self::assertStringContainsString('Show all 6 flow types', $section->filter('[data-more-flow-types] > summary')->text());
    }

    public function test_every_count_of_a_flow_type_links_to_its_traces(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $work = $this->workingOrders();
        $this->runWork($work);
        $this->pulseHavingHeardFrom($client, ['orders' => $work]);

        $crawler = $client->request('GET', '/traces');

        self::assertSame('/traces/orders/work.fail', $crawler->filter('[data-flow-type="work.fail"] [data-stat="runs"] a')->attr('href'));
        self::assertSame('/traces/orders/work.fail', $crawler->filter('[data-flow-type="work.fail"] [data-stat="runsWithErrors"] a')->attr('href'));
        self::assertSame('/traces?service=orders', $crawler->filter('[data-service-section="orders"] [data-summary-state] a')->attr('href'));
    }

    public function test_the_service_filter_shows_only_that_service_under_its_header_with_the_traces_tab_active(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $billing = $this->tracedBilling();
        $orders->sendCommandWithRouting('order.place', ['orderId' => 1]);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders, 'billing' => $billing]);

        $crawler = $client->request('GET', '/traces', ['service' => 'orders']);

        self::assertResponseIsSuccessful();
        self::assertSame(['orders'], $crawler->filter('[data-service-section]')->each(static fn (Crawler $section): string => (string) $section->attr('data-service-section')));
        self::assertSame('/traces?service=orders', $crawler->filter('[data-service-tab="traces"][aria-current="page"]')->attr('href'));
        self::assertSame('/traces', $crawler->filter('[data-scope-chip] a')->attr('href'));
        self::assertSame('orders', $crawler->filter('[data-crumbs] li')->eq(2)->text());
        self::assertSame('1', $crawler->filter('[data-flow-type="order.place"] [data-stat="runs"]')->text());
    }

    public function test_a_service_that_pulse_does_not_know_is_reported_with_the_way_back_to_every_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();

        $crawler = $client->request('GET', '/traces', ['service' => 'warehouse']);

        self::assertResponseIsSuccessful();
        self::assertSame('unknown', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertStringContainsString('PULSE_SERVICES', $crawler->filter('[data-empty]')->text());
        self::assertSame('/traces', $crawler->filter('[data-empty] a')->attr('href'));
    }

    public function test_a_trace_flow_type_step_and_p95_are_explained_where_they_first_appear(): void
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $orders = $this->tracedOrders();
        $orders->sendCommandWithRouting('order.place', ['orderId' => 1]);
        $this->pulseHavingHeardFrom($client, ['orders' => $orders]);

        $crawler = $client->request('GET', '/traces');

        foreach (['trace', 'flow-type', 'span', 'p95', 'errors-recorded'] as $term) {
            self::assertGreaterThan(0, $crawler->filter(sprintf('[data-concept="%s"]', $term))->count(), $term);
        }
        self::assertStringContainsString('7 days', $crawler->filter('[data-service-section="orders"] [data-retention]')->text());
    }

    /**
     * @return list<string>
     */
    private function flowTypesOf(Crawler $crawler, string $service): array
    {
        return $crawler->filter(sprintf('[data-service-section="%s"] [data-flow-type]', $service))->each(static fn (Crawler $row): string => (string) $row->attr('data-flow-type'));
    }

    private function workingOrders(): FlowTestSupport
    {
        $work = new class () {
            #[CommandHandler('work.slow', endpointId: 'work.slow.handler')]
            public function slow(array $payload): void
            {
                usleep(60000);
            }

            #[CommandHandler('work.fast', endpointId: 'work.fast.handler')]
            public function fast(array $payload): void
            {
            }

            #[CommandHandler('work.fail', endpointId: 'work.fail.handler')]
            public function fail(array $payload, CommandBus $commandBus): void
            {
                try {
                    $commandBus->sendWithRouting('work.charge', $payload);
                } catch (RuntimeException) {
                }
            }

            #[CommandHandler('work.charge', endpointId: 'work.charge.handler')]
            public function charge(array $payload): void
            {
                throw new RuntimeException('card declined');
            }

            #[CommandHandler('work.other', endpointId: 'work.other.handler')]
            public function other(array $payload): void
            {
            }

            #[CommandHandler('work.idle', endpointId: 'work.idle.handler')]
            public function idle(array $payload): void
            {
            }

            #[QueryHandler('work.lookup', endpointId: 'work.lookup.handler')]
            public function lookup(array $payload): string
            {
                return 'found';
            }
        };

        return $this->tracedService('orders', $work, DistributedServiceMap::initialize());
    }

    private function ordersFailingInsideTwoFlows(): object
    {
        return new class () {
            #[CommandHandler('order.first', endpointId: 'order.first.handler')]
            public function first(array $payload, CommandBus $commandBus): void
            {
                try {
                    $commandBus->sendWithRouting('step.fail', $payload);
                } catch (RuntimeException) {
                }
            }

            #[CommandHandler('order.second', endpointId: 'order.second.handler')]
            public function second(array $payload, CommandBus $commandBus): void
            {
                try {
                    $commandBus->sendWithRouting('step.fail', $payload);
                } catch (RuntimeException) {
                }
            }

            #[CommandHandler('step.fail', endpointId: 'step.fail.handler')]
            public function fail(array $payload): void
            {
                throw new RuntimeException('step failed');
            }
        };
    }

    private function billingFailingInsidePayment(): object
    {
        return new class () {
            #[CommandHandler('invoice.pay', endpointId: 'invoice.pay.handler')]
            public function pay(array $payload, CommandBus $commandBus): void
            {
                try {
                    $commandBus->sendWithRouting('payment.fail', $payload);
                } catch (RuntimeException) {
                }
            }

            #[CommandHandler('payment.fail', endpointId: 'payment.fail.handler')]
            public function fail(array $payload): void
            {
                throw new RuntimeException('payment failed');
            }
        };
    }

    private function runWork(FlowTestSupport $work): void
    {
        $work->sendCommandWithRouting('work.slow', ['id' => 1]);
        $work->sendCommandWithRouting('work.fast', ['id' => 1]);
        $work->sendCommandWithRouting('work.fail', ['id' => 1]);
        $work->sendQueryWithRouting('work.lookup', ['id' => 1]);
    }
}
