<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @internal
 */
final class CataloguePageTest extends WebTestCase
{
    use DistributedServicesTrait;

    public function test_catalogue_finds_the_service_handling_a_command_ignoring_case(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/catalogue?q=INVOICE.Create');

        self::assertResponseIsSuccessful();
        $handled = $crawler->filter('[data-service="billing"][data-message="invoice.create"]');
        self::assertCount(1, $handled);
        self::assertStringContainsString('command', $handled->text());
        self::assertStringContainsString('invoice.create.handler', $handled->text());
        self::assertCount(0, $crawler->filter('[data-message="order.place"]'));
    }

    public function test_catalogue_shows_which_handlers_emit_a_message(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/catalogue?q=invoice.created');

        self::assertStringContainsString('::create', $crawler->filter('[data-service="billing"][data-message="invoice.created"]')->text());
        self::assertStringContainsString('invoice.created.handler', $crawler->filter('[data-service="orders"][data-message="invoice.created"]')->text());
    }

    public function test_catalogue_without_a_query_lists_every_message_of_every_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/catalogue');

        self::assertCount(1, $crawler->filter('[data-service="orders"][data-message="order.place"]'));
        self::assertCount(1, $crawler->filter('[data-service="billing"][data-message="invoice.create"]'));
    }
}
