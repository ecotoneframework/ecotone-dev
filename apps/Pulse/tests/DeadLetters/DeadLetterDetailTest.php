<?php

declare(strict_types=1);

namespace App\Tests\DeadLetters;

use App\Tests\Support\DeadLetterServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class DeadLetterDetailTest extends ReleasingWebTestCase
{
    use DeadLetterServicesTrait;

    public function test_a_dead_letter_row_opens_its_detail_with_the_failure_and_the_message(): void
    {
        $client = $this->pulseWithOneFailure();

        $list = $this->freshView($client, '/dead-letters');
        $detail = $client->click($list->filter('[data-dead-letter] a')->link());

        self::assertResponseIsSuccessful();
        self::assertSame('order.place', $detail->filter('[data-detail="messageName"]')->text());
        self::assertSame('orders', $detail->filter('[data-detail="service"]')->text());
        self::assertStringContainsString('RuntimeException', $detail->filter('[data-detail="exception"]')->text());
        self::assertStringContainsString('place failed for order 7', $detail->filter('[data-detail="exception"]')->text());
        self::assertStringContainsString('#0', $detail->filter('[data-detail="stackTrace"]')->text());
        self::assertStringContainsString('orderId', $detail->filter('[data-detail="payload"]')->text());
        self::assertStringContainsString('application/', $detail->filter('[data-detail="contentType"]')->text());
        self::assertSame('pulse_dead_letter_work', $detail->filter('[data-detail="channel"]')->text());
        self::assertCount(1, $detail->filter('main'));
    }

    public function test_an_unknown_dead_letter_is_not_found(): void
    {
        $client = $this->pulseWithOneFailure();
        $this->freshView($client, '/dead-letters');

        $client->request('GET', '/dead-letters/orders/unknown-id');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_truncated_stack_trace_or_payload_is_marked(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->serviceWithDeadLetters('orders');
        $this->services['orders']->sendCommandWithRouting('order.place', ['orderId' => 1, 'note' => str_repeat('x', 6000)]);
        $this->replayWorkOf($this->services['orders']);

        $list = $this->freshView($client, '/dead-letters');
        $detail = $client->click($list->filter('[data-dead-letter] a')->link());

        self::assertCount(1, $detail->filter('[data-detail="payload"] [data-truncated]'));
    }

    public function test_the_detail_breadcrumbs_lead_back_through_the_service_hub(): void
    {
        $client = $this->pulseWithOneFailure();

        $list = $this->freshView($client, '/dead-letters');
        $detail = $client->click($list->filter('[data-dead-letter] a')->link());

        $crumbs = $detail->filter('nav[aria-label="Breadcrumb"] li');
        self::assertSame('Home', trim($crumbs->eq(0)->text()));
        self::assertSame('/services', $crumbs->eq(1)->filter('a')->attr('href'));
        self::assertSame('/services/orders', $crumbs->eq(2)->filter('a')->attr('href'));
        self::assertSame('/dead-letters?service=orders', $crumbs->eq(3)->filter('a')->attr('href'));
        self::assertMatchesRegularExpression('/^Message [0-9a-f]{8}…$/', trim($crumbs->eq(4)->text()));
    }

    public function test_a_dead_letter_in_a_trace_links_to_the_trace_in_the_tracing_ui(): void
    {
        $client = $this->pulseWithOneFailureInTrace('5d7501860754beda3f3d35857d1891d5', 'http://localhost:16686/trace/{traceId}');

        $detail = $this->detailOfTheOnlyDeadLetter($client);

        $link = $detail->filter('a[data-external-trace]');
        self::assertCount(1, $link);
        self::assertSame('http://localhost:16686/trace/5d7501860754beda3f3d35857d1891d5', $link->attr('href'));
        self::assertSame('_blank', $link->attr('target'));
        self::assertSame('noopener', $link->attr('rel'));
        self::assertSame('/traces/trace/5d7501860754beda3f3d35857d1891d5', $detail->filter('a[data-view-trace]')->attr('href'));
    }

    #[DataProvider('low_64_bits_of_trace_ids')]
    public function test_the_tracing_ui_link_can_carry_the_low_64_bits_of_the_trace_id_as_a_decimal(string $traceId, string $low64): void
    {
        $client = $this->pulseWithOneFailureInTrace($traceId, 'https://app.datadoghq.com/apm/trace/{traceIdLow64}');

        $detail = $this->detailOfTheOnlyDeadLetter($client);

        self::assertSame('https://app.datadoghq.com/apm/trace/' . $low64, $detail->filter('a[data-external-trace]')->attr('href'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function low_64_bits_of_trace_ids(): iterable
    {
        yield 'below the signed 64-bit limit' => ['5d7501860754beda3f3d35857d1891d5', '4556857245414494677'];
        yield 'above the signed 64-bit limit' => ['4bf92f3577b34da6a3ce929d0e0e4736', '11803532876627986230'];
        yield 'all bits set' => ['0000000000000000ffffffffffffffff', '18446744073709551615'];
        yield 'leading zeros' => ['ffffffffffffffff0000000000000001', '1'];
    }

    public function test_a_dead_letter_in_a_trace_has_no_tracing_ui_link_when_no_trace_url_is_configured(): void
    {
        $client = $this->pulseWithOneFailureInTrace('5d7501860754beda3f3d35857d1891d5', null);

        $detail = $this->detailOfTheOnlyDeadLetter($client);

        self::assertCount(1, $detail->filter('a[data-view-trace]'));
        self::assertCount(0, $detail->filter('a[data-external-trace]'));
    }

    public function test_a_dead_letter_outside_a_trace_has_no_tracing_ui_link(): void
    {
        $this->configurePulseWithServices('orders');
        $this->exportPulseVariable('PULSE_TRACE_URL', 'http://localhost:16686/trace/{traceId}');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->failOrder($this->serviceWithDeadLetters('orders'), 'order.place', 7);

        $detail = $this->detailOfTheOnlyDeadLetter($client);

        self::assertCount(0, $detail->filter('a[data-external-trace]'));
    }

    public function test_the_detail_starts_with_a_verdict_naming_the_message_the_service_and_the_exception(): void
    {
        $client = $this->pulseWithOneFailure();

        $detail = $this->detailOfTheOnlyDeadLetter($client);

        $verdict = $detail->filter('[data-verdict]');
        self::assertCount(1, $verdict);
        self::assertSame('warning', $verdict->attr('data-verdict'));
        $sentence = $verdict->filter('[data-verdict-sentence]')->text();
        self::assertStringContainsString('order.place', $sentence);
        self::assertStringContainsString('orders', $sentence);
        self::assertStringContainsString('RuntimeException', $sentence);
        self::assertStringContainsString('UTC', $sentence);
        self::assertStringContainsString('place failed for order 7', $verdict->filter('[data-detail="exception"]')->text());
    }

    public function test_the_detail_is_shown_under_the_failures_tab_of_its_service(): void
    {
        $client = $this->pulseWithOneFailure();

        $detail = $this->detailOfTheOnlyDeadLetter($client);

        self::assertSame('page', $detail->filter('[data-service-tab="failures"]')->attr('aria-current'));
    }

    public function test_the_page_reads_from_the_verdict_through_facts_actions_and_related_links_to_the_raw_data(): void
    {
        $client = $this->pulseWithOneFailureInTrace('5d7501860754beda3f3d35857d1891d5', null);

        $detail = $this->detailOfTheOnlyDeadLetter($client);

        $html = $detail->filter('main')->html();
        $positions = array_map(static fn (string $hook): int|false => strpos($html, $hook), ['data-verdict=', 'data-detail-facts', 'data-detail-actions', 'data-before-failure', 'data-raw-data']);
        self::assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
        self::assertLessThanOrEqual(8, $detail->filter('[data-detail-facts] dt')->count());
    }

    public function test_a_dead_letter_alone_in_its_failure_offers_no_others_to_open_or_act_on(): void
    {
        $client = $this->pulseWithOneFailure();

        $detail = $this->detailOfTheOnlyDeadLetter($client);

        self::assertCount(0, $detail->filter('[data-similar]'));
        self::assertCount(0, $detail->filter('[data-failure-actions]'));
        self::assertSame('1 of 1 with this failure', trim($detail->filter('[data-sibling-position]')->text()));
        self::assertCount(0, $detail->filter('a[data-sibling="previous"]'));
        self::assertCount(0, $detail->filter('a[data-sibling="next"]'));
    }

    public function test_previous_and_next_walk_the_dead_letters_of_the_same_failure_newest_first(): void
    {
        $client = $this->pulseWithTwoFailuresOfTheSameKind();
        $list = $this->freshView($client, '/dead-letters');

        $newest = $client->click($list->filter('[data-group-open]')->link());
        self::assertSame('1 of 2 with this failure', trim($newest->filter('[data-sibling-position]')->text()));
        self::assertCount(0, $newest->filter('a[data-sibling="previous"]'));

        $older = $client->click($newest->filter('a[data-sibling="next"]')->link());
        self::assertSame('2 of 2 with this failure', trim($older->filter('[data-sibling-position]')->text()));
        self::assertNotSame($newest->filter('[data-detail="messageId"]')->text(), $older->filter('[data-detail="messageId"]')->text());
        self::assertCount(0, $older->filter('a[data-sibling="next"]'));

        $back = $client->click($older->filter('a[data-sibling="previous"]')->link());
        self::assertSame($newest->filter('[data-detail="messageId"]')->text(), $back->filter('[data-detail="messageId"]')->text());
    }

    public function test_the_way_back_returns_to_the_list_with_the_filters_it_was_opened_from(): void
    {
        $client = $this->pulseWithTwoFailuresOfTheSameKind();
        $list = $this->freshView($client, '/dead-letters?service=orders&messageName=order.place');

        $detail = $client->click($list->filter('[data-dead-letter] a')->link());
        self::assertSame('/dead-letters?service=orders&messageName=order.place', $detail->filter('a[data-back-to-list]')->attr('href'));

        $sibling = $client->click($detail->filter('a[data-sibling]')->link());
        self::assertSame('/dead-letters?service=orders&messageName=order.place', $sibling->filter('a[data-back-to-list]')->attr('href'));
    }

    public function test_the_way_back_from_the_unfiltered_list_returns_to_it(): void
    {
        $client = $this->pulseWithOneFailure();

        $detail = $this->detailOfTheOnlyDeadLetter($client);

        self::assertSame('/dead-letters', $detail->filter('a[data-back-to-list]')->attr('href'));
    }

    public function test_the_trace_links_are_offered_as_the_way_to_see_what_happened_before_the_failure(): void
    {
        $client = $this->pulseWithOneFailureInTrace('5d7501860754beda3f3d35857d1891d5', 'http://localhost:16686/trace/{traceId}');

        $detail = $this->detailOfTheOnlyDeadLetter($client);

        $related = $detail->filter('[data-before-failure]');
        self::assertCount(1, $related->filter('a[data-view-trace]'));
        self::assertCount(1, $related->filter('a[data-view-graph]'));
        self::assertCount(1, $related->filter('a[data-external-trace]'));
        self::assertStringContainsString('trace', $related->filter('p')->first()->text());
    }

    public function test_the_stack_trace_is_collapsed_and_the_application_frames_are_shown_first(): void
    {
        $client = $this->pulseWithOneFailure();

        $detail = $this->detailOfTheOnlyDeadLetter($client);

        self::assertNull($detail->filter('details[data-stack-trace]')->attr('open'));
        $application = $detail->filter('[data-stack-frames] [data-frame="application"]');
        self::assertGreaterThanOrEqual(1, $application->count());
        self::assertStringContainsString('DeadLetterServicesTrait.php', $application->first()->text());
        foreach ($application->each(static fn (Crawler $frame): string => (string) $frame->attr('title')) as $file) {
            self::assertStringNotContainsString('/vendor/', $file);
            self::assertStringNotContainsString('/packages/Ecotone/', $file);
        }
        $framework = $detail->filter('[data-stack-frames] details[data-framework-frames]');
        self::assertNull($framework->attr('open'));
        self::assertStringContainsString((string) $framework->filter('[data-frame="framework"]')->count() . ' framework frames', $framework->filter('summary')->text());
    }

    public function test_frames_under_vendor_count_as_framework_frames_and_the_original_trace_stays_whole(): void
    {
        $stackTrace = "#0 /app/vendor/ecotone/ecotone/src/Messaging/Handler/Processor/MethodInvoker/AroundMethodInvocation.php(66): App\\Payment\\Checkout->charge()\n#1 /app/src/Payment/Checkout.php(35): App\\Payment\\PaymentProvider->charge()\n#2 [internal function]: Ecotone\\Messaging\\Channel\\DirectChannel->send()\n#3 {main}";
        $client = $this->pulseShowing(['stackTrace' => $stackTrace, 'exceptionFile' => '/app/src/Payment/PaymentProvider.php', 'exceptionLine' => 24]);

        $detail = $client->request('GET', '/dead-letters/orders/m-1');

        self::assertSame(['PaymentProvider.php:24', 'Checkout.php:35'], $detail->filter('[data-stack-frames] [data-frame="application"] [data-frame-location]')->each(static fn (Crawler $frame): string => $frame->text()));
        self::assertSame('3 framework frames', $detail->filter('[data-stack-frames] details[data-framework-frames] summary [data-summary-state]')->text());
        self::assertSame($stackTrace, $detail->filter('[data-detail="stackTrace"]')->text('', false));
    }

    public function test_the_message_headers_are_listed_collapsed_in_the_raw_data_without_the_stack_trace(): void
    {
        $client = $this->pulseWithOneFailure();

        $detail = $this->detailOfTheOnlyDeadLetter($client);

        $headers = $detail->filter('[data-raw-data] details[data-raw="headers"]');
        self::assertCount(1, $headers);
        self::assertNull($headers->attr('open'));
        $content = $headers->filter('[data-raw-content]')->text('', false);
        self::assertStringContainsString('"id": "' . $detail->filter('[data-detail="messageId"]')->text() . '"', $content);
        self::assertStringContainsString('"correlationId"', $content);
        self::assertStringNotContainsString('stacktrace', $content);
        self::assertSame('payload', $detail->filter('[data-raw-data] details[data-raw]')->first()->attr('data-raw'));
    }

    public function test_the_handler_that_failed_is_named_from_what_the_message_headers_carry(): void
    {
        $stackTrace = "#0 /app/src/Payment/Checkout.php(35): App\\Payment\\PaymentProvider->charge()\n#1 /app/vendor/ecotone/ecotone/src/Messaging/Handler/Processor/MethodInvoker/AroundMethodInvocation.php(66): App\\Payment\\Checkout->charge(Object(App\\Payment\\ChargeCard))\n#2 {main}";
        $client = $this->pulseShowing(['stackTrace' => $stackTrace, 'headers' => ['id' => 'm-1', 'routingSlip' => 'charge_card.target.execute']]);

        $detail = $client->request('GET', '/dead-letters/orders/m-1');

        self::assertSame('Checkout::charge', $detail->filter('[data-detail="handler"]')->text());
        self::assertSame('App\\Payment\\Checkout::charge', $detail->filter('[data-detail="handler"]')->attr('title'));
        self::assertSame('charge_card', $detail->filter('[data-detail="endpoint"]')->text());
    }

    public function test_no_handler_is_named_when_the_headers_carry_none(): void
    {
        $client = $this->pulseShowing(['headers' => ['id' => 'm-1']]);

        $detail = $client->request('GET', '/dead-letters/orders/m-1');

        self::assertCount(0, $detail->filter('[data-detail="handler"]'));
        self::assertCount(0, $detail->filter('[data-detail="endpoint"]'));
    }

    public function test_a_stack_trace_cut_by_the_service_is_called_the_stored_stack_trace_not_the_full_one(): void
    {
        $client = $this->pulseShowing(['stackTrace' => "#0 /app/src/Payment/Checkout.php(35): App\\Payment\\PaymentProvider->charge()\n... [truncated]", 'exceptionFile' => '/app/src/Payment/PaymentProvider.php', 'exceptionLine' => 24]);

        $detail = $client->request('GET', '/dead-letters/orders/m-1');

        self::assertStringContainsString('The stored stack trace (cut at 4 KB)', $detail->filter('[data-stack-frames]')->text());
        self::assertStringNotContainsString('The full stack trace', $detail->filter('[data-stack-frames]')->text());
    }

    public function test_a_snapshot_without_headers_says_they_are_not_reported(): void
    {
        $client = $this->pulseShowing([]);

        $detail = $client->request('GET', '/dead-letters/orders/m-1');

        self::assertCount(0, $detail->filter('details[data-raw="headers"]'));
        self::assertStringContainsString('headers', $detail->filter('[data-headers-missing]')->text());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function pulseShowing(array $overrides): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $entry = $overrides + ['messageId' => 'm-1', 'failureId' => 'aaaaaaaaaaaa', 'exceptionFile' => '', 'exceptionLine' => 0, 'messageName' => 'order.place', 'failedAt' => gmdate('c'), 'exceptionClass' => 'RuntimeException', 'exceptionMessage' => 'boom', 'stackTrace' => '', 'payload' => '{"orderId":1}', 'contentType' => 'application/json', 'channel' => 'orders_async', 'traceId' => null];
        $this->services['orders'] = $this->serviceAnsweringWith('orders', json_encode([
            'schemaVersion' => 1,
            'serviceName' => 'orders',
            'takenAt' => gmdate('c'),
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []],
            'topology' => ['serviceMaps' => []],
            'deadLetters' => ['total' => 1, 'groups' => [['failureId' => 'aaaaaaaaaaaa', 'messageName' => 'order.place', 'exceptionClass' => 'RuntimeException', 'exceptionFile' => $entry['exceptionFile'], 'exceptionLine' => $entry['exceptionLine'], 'exceptionMessage' => 'boom', 'count' => 1, 'firstFailedAt' => $entry['failedAt'], 'lastFailedAt' => $entry['failedAt']]], 'recent' => [$entry]],
        ], JSON_THROW_ON_ERROR));
        $this->freshView($client, '/dead-letters');

        return $client;
    }

    private function pulseWithTwoFailuresOfTheSameKind(): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceWithDeadLetters('orders');
        $this->failOrder($orders, 'order.place', 7);
        $this->failOrder($orders, 'order.place', 8);

        return $client;
    }

    private function pulseWithOneFailureInTrace(string $traceId, ?string $traceUrl): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        if ($traceUrl !== null) {
            $this->exportPulseVariable('PULSE_TRACE_URL', $traceUrl);
        }
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->failOrderInTrace($this->serviceWithDeadLetters('orders'), 'order.place', 7, $traceId);

        return $client;
    }

    private function detailOfTheOnlyDeadLetter(KernelBrowser $client): Crawler
    {
        $list = $this->freshView($client, '/dead-letters');

        return $client->click($list->filter('[data-dead-letter] a')->link());
    }

    private function pulseWithOneFailure(): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->failOrder($this->serviceWithDeadLetters('orders'), 'order.place', 7);

        return $client;
    }

    private function freshView(KernelBrowser $client, string $uri): Crawler
    {
        $client->request('POST', '/refresh');
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', $uri);
    }
}
