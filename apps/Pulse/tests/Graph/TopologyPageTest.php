<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @internal
 */
final class TopologyPageTest extends WebTestCase
{
    use DistributedServicesTrait;

    public function test_topology_shows_which_service_sends_commands_to_which_over_which_channel(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/topology');

        self::assertResponseIsSuccessful();
        $route = $crawler->filter('[data-command-route="orders>billing"]');
        self::assertCount(1, $route);
        self::assertStringContainsString($this->serviceChannels['billing'], $route->text());
        self::assertStringContainsString('invoice.*', $crawler->filter('[data-event-subscription="orders"]')->text());
    }

    public function test_a_consistent_topology_has_no_mismatches(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/topology');

        self::assertCount(0, $crawler->filter('[data-mismatch]'));
    }

    public function test_topology_lists_every_kind_of_mismatch(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersWithMismatches(), 'billing' => $this->serviceHandlingCommandX('billing')]);

        $crawler = $client->request('GET', '/topology');

        $mismatches = $crawler->filter('[data-mismatch]')->each(fn ($mismatch) => $mismatch->attr('data-mismatch') . ': ' . $mismatch->text());
        self::assertContains('unconfigured-command-target: orders maps commands for shipping, which is not configured in Pulse. Add shipping to PULSE_SERVICES, or remove the command mapping from the Service Map of orders.', $mismatches);
        self::assertContains('unmapped-command-target: orders sends commands to billing, but its Service Map has no command mapping for billing. Add DistributedServiceMap::initialize()->withCommandMapping(\'billing\', <channel>) to a #[ServiceContext] of orders.', $mismatches);
        self::assertContains('unsubscribed-event: orders publishes order.placed, but no service subscribes to it. Add a #[Distributed] #[EventHandler(\'order.placed\')] to a service and a withEventMapping() entry whose keys match order.placed.', $mismatches);
        self::assertContains('unpublished-subscription: orders subscribes to refund.*, but no service publishes an event matching it. Publish it with DistributedBus::publishEvent(), or remove refund.* from withEventMapping().', $mismatches);
    }

    public function test_topology_diagram_keeps_services_with_similar_or_reserved_names_apart(): void
    {
        $this->configurePulseWithServices('orders', 'a-b', 'a_b', 'end');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersMappingCommandsTo('a-b', 'a_b', 'end')]);

        $crawler = $client->request('GET', '/topology');

        $lines = explode("\n", rtrim($crawler->filter('pre.mermaid')->getNode(0)->textContent));
        $declared = array_values(array_filter($lines, fn (string $line) => preg_match('/^    \w+\["/', $line) === 1));
        self::assertCount(4, array_unique($declared));
        self::assertCount(4, array_unique(array_map(fn (string $line) => explode('[', trim($line))[0], $declared)));
        self::assertSame([], array_filter($lines, fn (string $line) => preg_match('/^    (end|graph|subgraph)\b/', $line) === 1));
    }

    public function test_event_mismatches_are_withheld_while_a_configured_service_is_not_answering(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling()]);
        $this->advanceTimeBy(20);

        $crawler = $client->request('GET', '/topology');

        self::assertStringContainsString('billing', $crawler->filter('[data-notice="incomplete"]')->text());
        self::assertCount(0, $crawler->filter('[data-mismatch="unpublished-subscription"]'));
        self::assertCount(0, $crawler->filter('[data-mismatch="unsubscribed-event"]'));
        self::assertCount(1, $crawler->filter('[data-mismatch="silent-command-target"]'));
    }

    public function test_data_from_a_service_that_stopped_answering_is_marked_stale(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);
        $this->advanceTimeBy(70);
        $client->request('GET', '/topology');
        $this->advanceTimeBy(20);

        $crawler = $client->request('GET', '/topology');

        self::assertSame('true', $crawler->filter('[data-command-route="orders>billing"]')->attr('data-stale'));
        self::assertSame('true', $crawler->filter('[data-event-subscription="orders"]')->attr('data-stale'));
        self::assertCount(2, $crawler->filter('[data-notice="stale"]'));
        self::assertCount(1, $crawler->filter('[data-mismatch="silent-command-target"]'));
    }
}
