<?php

declare(strict_types=1);

namespace App\Tests\DeadLetters;

use App\Tests\Support\DeadLetterServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use LogicException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class DeadLetterListTest extends ReleasingWebTestCase
{
    use DeadLetterServicesTrait;

    public function test_the_list_shows_failed_messages_of_every_service_with_their_failure(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        self::assertCount(4, $crawler->filter('[data-dead-letter]'));
        $rows = implode("\n", $crawler->filter('[data-dead-letter][data-service="orders"][data-message-name="order.place"]')->each(static fn (Crawler $row) => $row->text()));
        self::assertStringContainsString(RuntimeException::class, $rows);
        self::assertStringContainsString('place failed for order 1', $rows);
        self::assertStringContainsString('place failed for order 3', $rows);
        self::assertStringContainsString(gmdate('Y-m-d'), $rows);
        self::assertCount(1, $crawler->filter('[data-dead-letter][data-service="invoices"]'));
    }

    public function test_every_row_checkbox_has_an_accessible_name_and_no_table_header_is_empty(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        $boxes = $crawler->filter('[data-dead-letter] input[type="checkbox"]');
        self::assertCount(4, $boxes);
        foreach ($boxes as $box) {
            self::assertNotSame('', trim((string) $box->getAttribute('aria-label')));
        }
        foreach ($crawler->filter('th') as $header) {
            self::assertNotSame('', trim($header->textContent));
        }
    }

    public function test_totals_and_groups_are_shown_per_service(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        self::assertSame('3', $crawler->filter('[data-service-total="orders"]')->text());
        self::assertSame('1', $crawler->filter('[data-service-total="invoices"]')->text());
        $group = $crawler->filter('[data-group][data-service="orders"][data-message-name="order.cancel"]');
        self::assertCount(1, $group);
        self::assertStringContainsString(LogicException::class, $group->text());
    }

    public function test_a_service_without_dead_letter_is_marked_as_not_configured(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        self::assertStringContainsString('dead letter not configured', $crawler->filter('[data-not-configured="billing"]')->text());
        self::assertCount(0, $crawler->filter('[data-service-total="billing"]'));
    }

    public function test_the_list_can_be_narrowed_to_one_service(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters?service=invoices');

        self::assertCount(1, $crawler->filter('[data-dead-letter]'));
        self::assertCount(1, $crawler->filter('[data-dead-letter][data-service="invoices"]'));
    }

    public function test_the_list_can_be_filtered_by_message_name_and_exception_class(): void
    {
        $client = $this->pulseWithFailures();

        $byName = $this->freshView($client, '/dead-letters?messageName=order.cancel');
        $byException = $this->freshView($client, '/dead-letters?exceptionClass=' . urlencode(RuntimeException::class));

        self::assertCount(1, $byName->filter('[data-dead-letter]'));
        self::assertSame('order.cancel', $byName->filter('[data-dead-letter]')->attr('data-message-name'));
        self::assertCount(2, $byException->filter('[data-dead-letter]'));
        self::assertCount(2, $byException->filter('[data-dead-letter][data-service="orders"]'));
    }

    public function test_the_list_can_be_filtered_by_failure_time(): void
    {
        $client = $this->pulseWithFailures();

        $none = $this->freshView($client, '/dead-letters?failedFrom=2999-01-01T00:00:00');
        $all = $this->freshView($client, '/dead-letters?failedFrom=2000-01-01T00:00:00&failedTo=2999-01-01T00:00:00');

        self::assertCount(0, $none->filter('[data-dead-letter]'));
        self::assertCount(4, $all->filter('[data-dead-letter]'));
    }

    public function test_the_page_tells_that_it_lists_the_latest_hundred_while_actions_apply_to_all(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        self::assertStringContainsString('latest 100', $crawler->filter('[data-list-note]')->text());
        self::assertStringContainsString('all stored dead letters', $crawler->filter('[data-list-note]')->text());
    }

    private function pulseWithFailures(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'invoices', 'billing');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceWithDeadLetters('orders');
        $this->failOrder($orders, 'order.place', 1);
        $this->failOrder($orders, 'order.cancel', 2);
        $this->failOrder($orders, 'order.place', 3);
        $this->services['invoices'] = $this->serviceAnsweringWith('invoices', $this->invoicesSnapshot());
        $this->serviceWithoutDeadLetters('billing');

        return $client;
    }

    private function invoicesSnapshot(): string
    {
        $entry = ['messageId' => 'invoice-1', 'messageName' => 'invoice.issue', 'failedAt' => gmdate('c'), 'exceptionClass' => 'DomainException', 'exceptionMessage' => 'cannot issue invoice', 'stackTrace' => '#0 trace', 'payload' => '{"invoiceId":4}', 'contentType' => 'application/json', 'channel' => 'invoices'];

        return json_encode([
            'schemaVersion' => 1,
            'serviceName' => 'invoices',
            'takenAt' => gmdate('c'),
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []],
            'topology' => ['serviceMaps' => []],
            'deadLetters' => ['total' => 1, 'groups' => [['messageName' => 'invoice.issue', 'exceptionClass' => 'DomainException', 'count' => 1]], 'recent' => [$entry]],
        ], JSON_THROW_ON_ERROR);
    }

    private function freshView(KernelBrowser $client, string $uri): Crawler
    {
        $client->request('POST', '/refresh');
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', $uri);
    }
}
