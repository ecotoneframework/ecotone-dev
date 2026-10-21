<?php

declare(strict_types=1);

namespace App\Tests\Graph;

use App\Tests\Support\DistributedServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;

/**
 * @internal
 */
final class CataloguePageTest extends ReleasingWebTestCase
{
    use DistributedServicesTrait;

    public function test_catalogue_search_field_has_an_accessible_name(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/catalogue');

        self::assertNotSame('', trim((string) $crawler->filter('input[name="q"]')->attr('aria-label')));
    }

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

    public function test_catalogue_search_matches_the_endpoint_id_of_a_handler(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/catalogue?q=Create.Handler');

        self::assertCount(1, $crawler->filter('[data-message]'));
        self::assertCount(1, $crawler->filter('[data-service="billing"][data-message="invoice.create"]'));
    }

    public function test_catalogue_search_matches_the_name_of_an_emitting_handler(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->ordersSendingInvoiceCommandToBilling(), 'billing' => $this->billingCreatingInvoices()]);

        $crawler = $client->request('GET', '/catalogue?q=::place');

        self::assertCount(1, $crawler->filter('[data-service="orders"][data-message="invoice.create"]'));
    }

    public function test_catalogue_search_matches_the_asynchronous_channel_of_a_handler(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseWebClient();
        $this->pulseHavingHeardFrom($client, ['orders' => $this->serviceHandlingAsynchronousCommand('orders')]);

        $crawler = $client->request('GET', '/catalogue?q=SLOW_work');

        self::assertCount(1, $crawler->filter('[data-service="orders"][data-message="work.do"]'));
        self::assertStringContainsString('async on slow_work', $crawler->filter('[data-message="work.do"]')->text());
    }

    public function test_catalogue_explains_how_the_search_text_is_matched(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseWebClient();

        $crawler = $client->request('GET', '/catalogue');

        self::assertStringContainsString('contains the whole text, ignoring case', $crawler->filter('[data-search-help]')->text());
    }
}
