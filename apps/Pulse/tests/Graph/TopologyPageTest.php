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
        $this->configurePulseWithServices('orders', 'billing', 'payments');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersWithMismatches()]);

        $crawler = $client->request('GET', '/topology');

        $mismatches = $crawler->filter('[data-mismatch]')->each(fn ($mismatch) => $mismatch->attr('data-mismatch') . ': ' . $mismatch->text());
        self::assertContains('unconfigured-command-target: orders maps commands for shipping, which is not configured in Pulse. Add shipping to PULSE_SERVICES, or remove the command mapping from the Service Map of orders.', $mismatches);
        self::assertContains('unmapped-command-target: orders sends commands to billing, but its Service Map has no command mapping for billing. Add DistributedServiceMap::initialize()->withCommandMapping(\'billing\', <channel>) to a #[ServiceContext] of orders.', $mismatches);
        self::assertContains('unsubscribed-event: orders publishes order.placed, but no service subscribes to it. Add a #[Distributed] #[EventHandler(\'order.placed\')] to a service and a withEventMapping() entry whose keys match order.placed.', $mismatches);
        self::assertContains('unpublished-subscription: orders subscribes to refund.*, but no service publishes an event matching it. Publish it with DistributedBus::publishEvent(), or remove refund.* from withEventMapping().', $mismatches);
        self::assertContains('silent-command-target: orders maps commands for payments, which is configured in Pulse but did not answer. Check that payments runs with PulseConfiguration and consumes its channel.', $mismatches);
    }
}
