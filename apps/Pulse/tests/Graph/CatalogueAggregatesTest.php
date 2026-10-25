<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\NamedEvent;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Api\Modelling\WithEvents;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class CatalogueAggregatesTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_state_stored_aggregate_groups_its_commands_recorded_events_and_queries(): void
    {
        $crawler = $this->catalogueOf('/catalogue');

        $group = $crawler->filter('[data-group="aggregate"][data-service="orders"][data-aggregate="CatalogueBasket"]');
        self::assertCount(1, $group);
        self::assertCount(1, $group->filter('[data-message="basket.add"]'));
        self::assertStringContainsString('command', $group->filter('[data-message="basket.add"]')->text());
        self::assertStringContainsString('async on basket_work', $group->filter('[data-message="basket.add"]')->text());
        self::assertCount(1, self::message($group, CatalogueItemAdded::class));
        self::assertStringContainsString('event', self::message($group, CatalogueItemAdded::class)->text());
        self::assertCount(1, $group->filter('[data-message="basket.items"]'));
        self::assertStringContainsString('query', $group->filter('[data-message="basket.items"]')->text());
    }

    public function test_event_sourced_aggregate_groups_its_commands_returned_and_applied_events(): void
    {
        $crawler = $this->catalogueOf('/catalogue');

        $group = $crawler->filter('[data-group="aggregate"][data-service="orders"][data-aggregate="CatalogueShipment"]');
        self::assertCount(1, $group);
        self::assertCount(1, $group->filter('[data-message="shipment.dispatch"]'));
        self::assertCount(1, self::message($group, CatalogueShipmentDispatched::class));
        self::assertCount(0, $group->filter('[data-message="basket.add"]'));
    }

    public function test_messages_without_an_aggregate_fall_into_services_and_handlers(): void
    {
        $crawler = $this->catalogueOf('/catalogue');

        $group = $crawler->filter('[data-group="services"][data-service="orders"]');
        self::assertCount(1, $group);
        self::assertStringContainsString('Services and handlers', $group->text());
        self::assertCount(1, $group->filter('[data-message="report.run"]'));
        self::assertCount(1, $group->filter('[data-message="basket.checked_out"]'));
        self::assertCount(0, $group->filter('[data-message="basket.add"]'));
        self::assertCount(0, self::message($group, CatalogueShipmentDispatched::class));
    }

    public function test_event_lists_a_local_handler_as_subscriber(): void
    {
        $crawler = $this->catalogueOf('/catalogue');

        $subscribers = self::message($crawler->filter('[data-service="orders"]'), CatalogueItemAdded::class)->filter('[data-subscriber]');
        self::assertCount(1, $subscribers);
        self::assertSame('orders', $subscribers->attr('data-subscriber-service'));
        self::assertStringContainsString('::whenItemAdded', $subscribers->text());
    }

    public function test_distributed_event_lists_the_handlers_of_every_service_subscribed_through_the_service_map(): void
    {
        $crawler = $this->catalogueOf('/catalogue');

        $subscribers = $crawler->filter('[data-group="services"][data-service="orders"] [data-message="basket.checked_out"] [data-subscriber]');
        $services = $subscribers->each(fn (Crawler $subscriber) => $subscriber->attr('data-subscriber-service'));
        sort($services);
        self::assertSame(['billing', 'shipping'], $services);
        self::assertStringContainsString('billing.checked_out.handler', $subscribers->filter('[data-subscriber-service="billing"]')->text());
        self::assertStringContainsString('::whenCheckedOut', $subscribers->filter('[data-subscriber-service="shipping"]')->text());
    }

    public function test_service_selector_offers_every_service_and_keeps_the_chosen_one(): void
    {
        $crawler = $this->catalogueOf('/catalogue?service=billing');

        $select = $crawler->filter('select[name="service"]');
        self::assertNotSame('', trim((string) $select->attr('aria-label')));
        self::assertSame(['', 'billing', 'orders', 'shipping'], $select->filter('option')->each(fn (Crawler $option) => $option->attr('value')));
        self::assertSame('billing', $select->filter('option[selected]')->attr('value'));
    }

    public function test_selecting_a_service_lists_its_own_groups_and_greys_those_of_the_services_it_talks_to(): void
    {
        $crawler = $this->catalogueOf('/catalogue?service=orders');

        self::assertCount(3, $crawler->filter('[data-group][data-service="orders"]:not([data-greyed])'));
        self::assertCount(2, $crawler->filter('[data-group][data-greyed]'));
        self::assertSame(['billing', 'shipping'], array_values(array_unique($crawler->filter('[data-group][data-greyed]')->each(fn (Crawler $group) => $group->attr('data-service')))));
    }

    public function test_selected_service_combines_with_the_search(): void
    {
        $crawler = $this->catalogueOf('/catalogue?service=orders&q=shipment');

        self::assertCount(1, $crawler->filter('[data-group]'));
        self::assertCount(1, $crawler->filter('[data-group="aggregate"][data-aggregate="CatalogueShipment"]'));
        self::assertCount(0, $crawler->filter('[data-message="basket.add"]'));
    }

    public function test_search_finds_the_messages_of_an_aggregate_by_its_name(): void
    {
        $crawler = $this->catalogueOf('/catalogue?q=cataloguebasket');

        self::assertCount(1, $crawler->filter('[data-group]'));
        self::assertCount(3, $crawler->filter('[data-message]'));
    }

    public function test_unknown_service_in_the_query_lists_nothing(): void
    {
        $crawler = $this->catalogueOf('/catalogue?service=unknown');

        self::assertCount(0, $crawler->filter('[data-group]'));
        self::assertStringContainsString('Nothing matches', $crawler->text());
    }

    private static function message(Crawler $scope, string $routingKey): Crawler
    {
        return $scope->filter('[data-message]')->reduce(fn (Crawler $row) => $row->attr('data-message') === $routingKey);
    }

    private function catalogueOf(string $uri): Crawler
    {
        $this->configurePulseWithServices('orders', 'billing', 'shipping');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersWithAggregates(), 'billing' => $this->billingSubscribedToCheckout(), 'shipping' => $this->shippingSubscribedToCheckout()]);

        return $client->request('GET', $uri);
    }

    private function ordersWithAggregates(): FlowTestSupport
    {
        $services = new class () {
            public function __construct(private ?DistributedBus $distributedBus = null)
            {
            }

            #[CommandHandler('report.run', endpointId: 'report.run.handler')]
            public function run(array $payload): void
            {
                $this->distributedBus->publishEvent('basket.checked_out', '{}');
            }

            #[EventHandler(endpointId: 'basket.item_added.handler')]
            public function whenItemAdded(CatalogueItemAdded $event): void
            {
            }
        };

        return $this->distributedService(
            'orders',
            $services,
            DistributedServiceMap::initialize()
                ->withEventMapping($this->serviceChannels['billing'], ['basket.*'])
                ->withEventMapping($this->serviceChannels['shipping'], ['basket.checked_out']),
            [SimpleMessageChannelBuilder::createQueueChannel('basket_work')],
            [new CatalogueBasket(), new CatalogueShipment()],
        );
    }

    private function billingSubscribedToCheckout(): FlowTestSupport
    {
        $subscriber = new class () {
            #[Distributed]
            #[EventHandler('basket.checked_out', endpointId: 'billing.checked_out.handler')]
            public function handle(array $payload): void
            {
            }
        };

        return $this->distributedService('billing', $subscriber, DistributedServiceMap::initialize());
    }

    private function shippingSubscribedToCheckout(): FlowTestSupport
    {
        $subscriber = new class () {
            #[Distributed]
            #[EventHandler('basket.checked_out', endpointId: 'shipping.checked_out.handler')]
            public function whenCheckedOut(array $payload): void
            {
            }
        };

        return $this->distributedService('shipping', $subscriber, DistributedServiceMap::initialize());
    }
}

#[NamedEvent('basket.item_added')]
final class CatalogueItemAdded
{
}

#[NamedEvent('shipment.dispatched')]
final class CatalogueShipmentDispatched
{
}

#[Aggregate]
final class CatalogueBasket
{
    use WithEvents;

    #[Identifier]
    private string $id = 'basket';

    #[Asynchronous('basket_work')]
    #[CommandHandler('basket.add', endpointId: 'basket.add.handler')]
    public static function add(array $payload): static
    {
        $basket = new static();
        $basket->recordThat(new CatalogueItemAdded());

        return $basket;
    }

    #[QueryHandler('basket.items')]
    public function items(): array
    {
        return [];
    }
}

#[EventSourcingAggregate]
final class CatalogueShipment
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $id = 'shipment';

    #[CommandHandler('shipment.dispatch')]
    public static function dispatch(array $payload): CatalogueShipmentDispatched
    {
        return new CatalogueShipmentDispatched();
    }

    #[Apply]
    public function whenDispatched(CatalogueShipmentDispatched $event): void
    {
        $this->id = 'shipment';
    }
}
