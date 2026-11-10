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
        $group = $crawler->filter('[data-group][data-service="orders"][data-message-name="order.place"]');
        self::assertSame(RuntimeException::class, $group->filter('[data-failure-class]')->text());
        self::assertStringContainsString('place failed for order 1', $group->text());
        self::assertStringContainsString('place failed for order 3', $group->text());
        self::assertStringContainsString(gmdate('Y-m-d'), implode("\n", $group->filter('[data-dead-letter]')->each(static fn (Crawler $row) => $row->text())));
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
        self::assertSame('2', $crawler->filter('[data-service-failures="orders"]')->text());
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

    public function test_a_service_with_dead_letter_storage_and_no_failures_says_it_is_enabled_and_empty(): void
    {
        $this->configurePulseWithServices('quiet');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->serviceWithDeadLetters('quiet');

        $crawler = $this->freshView($client, '/dead-letters');

        self::assertCount(0, $crawler->filter('[data-not-configured]'));
        self::assertStringContainsString('No message has failed in quiet', $crawler->filter('[data-service-section="quiet"] [data-empty="nothing-yet"]')->text());
    }

    public function test_a_snapshot_without_the_dead_letter_field_is_read_by_its_dead_letters_section(): void
    {
        $this->configurePulseWithServices('legacy');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $snapshot = json_decode($this->invoicesSnapshot(), true);
        $snapshot['serviceName'] = 'legacy';
        $snapshot['deadLetters'] = ['total' => 0, 'groups' => [], 'recent' => []];
        $this->services['legacy'] = $this->serviceAnsweringWith('legacy', json_encode($snapshot));

        $crawler = $this->freshView($client, '/dead-letters');

        self::assertCount(0, $crawler->filter('[data-not-configured]'));
        self::assertCount(1, $crawler->filter('[data-service-section="legacy"] [data-empty]'));
    }

    public function test_a_service_without_dead_letter_storage_says_how_to_enable_it(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        $notice = $crawler->filter('[data-service-section="billing"] [data-enable-dead-letter]')->text();
        self::assertStringContainsString('withDeadLetter(true)', $notice);
        self::assertStringContainsString('billing', $notice);
        self::assertCount(0, $crawler->filter('[data-service-section="billing"] [data-empty="nothing-yet"]'));
        self::assertCount(1, $crawler->filter('[data-service-section="billing"] [data-empty="not-enabled"]'));
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
        self::assertStringContainsString('all stored failed messages', $crawler->filter('[data-list-note]')->text());
    }

    public function test_a_row_in_a_trace_links_to_the_trace_in_the_tracing_ui(): void
    {
        $client = $this->pulseWithOneFailureInTraceAndOneOutside('http://localhost:9411/zipkin/traces/{traceId}');

        $crawler = $this->freshView($client, '/dead-letters');

        $links = $crawler->filter('[data-dead-letter] a[data-external-trace]');
        self::assertCount(1, $links);
        self::assertSame('http://localhost:9411/zipkin/traces/5d7501860754beda3f3d35857d1891d5', $links->attr('href'));
        self::assertSame('_blank', $links->attr('target'));
        self::assertSame('noopener', $links->attr('rel'));
        self::assertCount(1, $crawler->filter('[data-dead-letter] a[data-view-trace]'));
    }

    public function test_rows_have_no_tracing_ui_link_when_no_trace_url_is_configured(): void
    {
        $client = $this->pulseWithOneFailureInTraceAndOneOutside(null);

        $crawler = $this->freshView($client, '/dead-letters');

        self::assertCount(2, $crawler->filter('[data-dead-letter]'));
        self::assertCount(1, $crawler->filter('[data-dead-letter] a[data-view-trace]'));
        self::assertCount(0, $crawler->filter('a[data-external-trace]'));
    }

    public function test_the_page_opens_with_a_verdict_counting_failed_messages_and_failure_groups_per_service(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        $verdict = $crawler->filter('[data-verdict]');
        self::assertCount(1, $verdict);
        self::assertSame('warning', $verdict->attr('data-verdict'));
        self::assertSame('4 failed messages in 3 failure groups: 3 in orders, 1 in invoices.', trim(preg_replace('/\s+/', ' ', $verdict->filter('[data-verdict-sentence]')->text())));
        self::assertSame('/dead-letters?service=orders', $verdict->filter('[data-verdict-sentence] a')->first()->attr('href'));
        $html = (string) $client->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'data-service-section'), strpos($html, 'data-verdict'));
    }

    public function test_a_list_without_failed_messages_says_where_dead_letter_storage_is_on(): void
    {
        $this->configurePulseWithServices('quiet');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->serviceWithDeadLetters('quiet');

        $crawler = $this->freshView($client, '/dead-letters');

        self::assertSame('ok', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('No failed messages: dead letter storage is on in quiet.', trim($crawler->filter('[data-verdict-sentence]')->text()));
    }

    public function test_services_with_failed_messages_come_first_and_a_service_without_any_is_one_line(): void
    {
        $this->configurePulseWithServices('quiet', 'invoices');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $quiet = json_decode($this->invoicesSnapshot(), true);
        $quiet['serviceName'] = 'quiet';
        $quiet['deadLetters'] = ['total' => 0, 'groups' => [], 'recent' => []];
        $this->services['quiet'] = $this->serviceAnsweringWith('quiet', json_encode($quiet));
        $this->services['invoices'] = $this->serviceAnsweringWith('invoices', $this->invoicesSnapshot());

        $crawler = $this->freshView($client, '/dead-letters');

        self::assertSame(['invoices', 'quiet'], $crawler->filter('[data-service-section]')->each(static fn (Crawler $section) => $section->attr('data-service-section')));
        $quiet = $crawler->filter('[data-service-section="quiet"]');
        self::assertCount(1, $quiet->filter('[data-empty="nothing-yet"]'));
        self::assertCount(0, $quiet->filter('table, select'));
    }

    public function test_failure_groups_are_sorted_by_size_and_their_messages_are_collapsed_behind_a_count(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        self::assertSame(['order.place', 'order.cancel'], $crawler->filter('[data-service-section="orders"] [data-group]')->each(static fn (Crawler $group) => $group->attr('data-message-name')));
        $messages = $crawler->filter('[data-group][data-message-name="order.place"] details[data-group-messages]');
        self::assertCount(1, $messages);
        self::assertNull($messages->attr('open'));
        self::assertStringContainsString('Messages in this failure group · 2 of 2 listed', $messages->filter('summary')->text());
    }

    public function test_the_messages_of_a_failure_open_once_the_list_is_narrowed_to_that_failure(): void
    {
        $client = $this->pulseWithFailures();
        $list = $this->freshView($client, '/dead-letters');
        $failure = $list->filter('[data-group][data-message-name="order.place"]')->attr('data-failure');

        $narrowed = $client->request('GET', '/dead-letters', ['service' => 'orders', 'failureId' => $failure]);

        self::assertNotNull($narrowed->filter('details[data-group-messages]')->attr('open'));
    }

    public function test_a_failure_says_where_it_failed_and_offers_one_primary_action_to_its_latest_message(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        $group = $crawler->filter('[data-group][data-message-name="order.place"]');
        $where = $group->filter('[data-failure-where] li')->each(static fn (Crawler $place) => $place->text());
        self::assertSame(['orders', 'pulse_dead_letter_work'], array_slice($where, 0, 2));
        self::assertMatchesRegularExpression('/^DeadLetterServicesTrait\.php:\d+$/', $where[2]);
        self::assertCount(1, $group->filter('.pri'));
        self::assertSame($group->filter('[data-dead-letter] a')->first()->attr('href'), $group->filter('a.pri[data-group-open]')->attr('href'));
        self::assertSame('Open the latest message', $group->filter('a.pri[data-group-open]')->text());
    }

    public function test_group_actions_name_the_count_and_what_they_act_on(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        $place = $crawler->filter('[data-group][data-message-name="order.place"]');
        $cancel = $crawler->filter('[data-group][data-message-name="order.cancel"]');
        self::assertSame('Replay 2 messages', trim($place->filter('[data-group-replay] button')->text()));
        self::assertSame('Delete 2 failed messages', trim($place->filter('[data-group-delete]')->text()));
        self::assertSame('Replay 1 message', trim($cancel->filter('[data-group-replay] button')->text()));
        self::assertSame('Delete 1 failed message', trim($cancel->filter('[data-group-delete]')->text()));
    }

    public function test_every_count_links_to_the_list_already_filtered(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        $count = $crawler->filter('[data-group][data-message-name="order.place"] a[data-failure-count]');
        self::assertSame('2 messages', $count->text());
        self::assertStringContainsString('service=orders', $count->attr('href'));
        self::assertStringContainsString('failureId=' . $crawler->filter('[data-group][data-message-name="order.place"]')->attr('data-failure'), $count->attr('href'));
        self::assertSame('/dead-letters?service=orders', $crawler->filter('[data-service-section="orders"] a[data-service-count]')->attr('href'));
    }

    public function test_the_filters_open_on_a_search_box_and_keep_the_others_collapsed_until_one_is_set(): void
    {
        $client = $this->pulseWithFailures();

        $unfiltered = $this->freshView($client, '/dead-letters');
        $byName = $client->request('GET', '/dead-letters?messageName=order.cancel');
        $byService = $client->request('GET', '/dead-letters?service=orders');

        self::assertCount(0, $unfiltered->filter('[data-filter-form] details input[name="search"]'));
        self::assertCount(1, $unfiltered->filter('[data-filter-form] input[name="search"]'));
        self::assertNull($unfiltered->filter('[data-filter-form] details[data-more-filters]')->attr('open'));
        self::assertNotNull($byName->filter('[data-filter-form] details[data-more-filters]')->attr('open'));
        self::assertNull($byService->filter('[data-filter-form] details[data-more-filters]')->attr('open'));
        self::assertStringContainsString('orders', $byService->filter('[data-scope-chip]')->text());
        self::assertSame('/dead-letters', $byService->filter('[data-scope-chip] a')->attr('href'));
    }

    public function test_a_list_scoped_to_one_service_shows_that_service_with_its_failures_tab_active(): void
    {
        $client = $this->pulseWithFailures();

        $unfiltered = $this->freshView($client, '/dead-letters');
        $scoped = $client->request('GET', '/dead-letters?service=orders');

        self::assertCount(0, $unfiltered->filter('[data-service-tabs]'));
        self::assertSame(['Home', 'Failed messages'], $unfiltered->filter('[data-crumbs] li')->each(static fn (Crawler $crumb) => $crumb->text()));
        self::assertSame('page', $scoped->filter('[data-service-tab="failures"]')->attr('aria-current'));
        self::assertSame(['Home', 'Services', 'orders', 'Failed messages'], $scoped->filter('[data-crumbs] li')->each(static fn (Crawler $crumb) => $crumb->text()));
    }

    public function test_the_rows_of_a_failure_leave_the_exception_to_the_failure_and_keep_message_id_and_time(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        self::assertSame(['Select', 'Message', 'Failed at (UTC)'], $crawler->filter('[data-group][data-message-name="order.place"] th')->each(static fn (Crawler $header) => trim($header->text())));
    }

    public function test_dead_letter_failure_group_replay_and_delete_are_explained_on_the_page(): void
    {
        $client = $this->pulseWithFailures();

        $crawler = $this->freshView($client, '/dead-letters');

        foreach (['dead-letter', 'failure', 'replay', 'delete'] as $term) {
            self::assertGreaterThan(0, $crawler->filter(sprintf('[data-concept="%s"]', $term))->count(), $term);
        }
    }

    public function test_the_filter_actions_name_how_many_stored_dead_letters_they_reach(): void
    {
        $client = $this->pulseWithFailures();
        $this->freshView($client, '/dead-letters');

        $byName = $client->request('GET', '/dead-letters?messageName=order.place');
        $byTime = $client->request('GET', '/dead-letters?messageName=order.place&failedFrom=2000-01-01T00:00:00');

        self::assertStringContainsString('all 2 stored failed messages matching this filter', $byName->filter('details[data-filter-actions] > summary')->text());
        self::assertSame('Delete 2 matching failed messages', trim($byName->filter('[data-filter-action-form] button[value="delete"]')->text()));
        self::assertStringContainsString('every stored failed message matching this filter', $byTime->filter('details[data-filter-actions] > summary')->text());
    }

    public function test_the_filter_actions_wait_for_a_condition_before_they_can_be_sent(): void
    {
        $client = $this->pulseWithFailures();
        $this->freshView($client, '/dead-letters');

        $byService = $client->request('GET', '/dead-letters?service=orders');

        self::assertNotNull($byService->filter('[data-filter-action-form] button[value="delete"]')->attr('disabled'));
        self::assertCount(1, $byService->filter('[data-filter-action-needs-condition]'));
    }

    private function pulseWithOneFailureInTraceAndOneOutside(?string $traceUrl): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        if ($traceUrl !== null) {
            $this->exportPulseVariable('PULSE_TRACE_URL', $traceUrl);
        }
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceWithDeadLetters('orders');
        $this->failOrderInTrace($orders, 'order.place', 1, '5d7501860754beda3f3d35857d1891d5');
        $this->failOrder($orders, 'order.place', 2);

        return $client;
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
        $entry = ['messageId' => 'invoice-1', 'failureId' => 'aaaaaaaaaaaa', 'exceptionFile' => '/srv/app/src/Invoice.php', 'exceptionLine' => 12, 'messageName' => 'invoice.issue', 'failedAt' => gmdate('c'), 'exceptionClass' => 'DomainException', 'exceptionMessage' => 'cannot issue invoice', 'stackTrace' => '#0 trace', 'payload' => '{"invoiceId":4}', 'contentType' => 'application/json', 'channel' => 'invoices'];

        return json_encode([
            'schemaVersion' => 1,
            'serviceName' => 'invoices',
            'takenAt' => gmdate('c'),
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []],
            'topology' => ['serviceMaps' => []],
            'deadLetters' => ['total' => 1, 'groups' => [['failureId' => 'aaaaaaaaaaaa', 'messageName' => 'invoice.issue', 'exceptionClass' => 'DomainException', 'exceptionFile' => '/srv/app/src/Invoice.php', 'exceptionLine' => 12, 'exceptionMessage' => 'cannot issue invoice', 'count' => 1, 'firstFailedAt' => gmdate('c'), 'lastFailedAt' => gmdate('c')]], 'recent' => [$entry]],
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
