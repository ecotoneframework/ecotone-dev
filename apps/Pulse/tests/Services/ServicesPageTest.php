<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\PulseServicesTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class ServicesPageTest extends WebTestCase
{
    use PulseServicesTrait;

    public function test_the_first_view_shows_every_service_as_waiting(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('waiting', $this->statusOf($crawler, 'orders'));
        self::assertSame('waiting', $this->statusOf($crawler, 'billing'));
    }

    public function test_services_answer_the_snapshot_request_the_first_view_sent(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseClient();
        $orders = $this->realService('orders');
        $billing = $this->realService('billing');

        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runService($billing, 'billing');
        $this->runPulseWorker($client->getKernel());
        $crawler = $client->request('GET', '/');

        self::assertSame('connected', $this->statusOf($crawler, 'orders'));
        self::assertSame('connected', $this->statusOf($crawler, 'billing'));
        $card = $crawler->filter('[data-service="orders"]');
        self::assertStringContainsString(PHP_VERSION, $card->text());
        self::assertStringContainsString('lite', $card->text());
        self::assertStringContainsString('2026-10-20 12:00:00', $card->text());
        self::assertStringContainsString('0s', $card->text());
    }

    public function test_a_service_that_never_answers_becomes_unreachable_after_the_reply_timeout(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $client->request('GET', '/');
        $this->advanceTimeBy(9);
        self::assertSame('waiting', $this->statusOf($client->request('GET', '/'), 'orders'));
        $this->advanceTimeBy(1);
        $crawler = $client->request('GET', '/');

        self::assertSame('unreachable', $this->statusOf($crawler, 'orders'));
        self::assertStringContainsString('never', $crawler->filter('[data-service="orders"]')->text());
    }

    public function test_an_unreachable_service_shows_when_it_was_last_seen(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        $orders = $this->realService('orders');

        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $this->advanceTimeBy(70);
        $client->request('GET', '/');
        $this->advanceTimeBy(10);
        $crawler = $client->request('GET', '/');

        self::assertSame('unreachable', $this->statusOf($crawler, 'orders'));
        $card = $crawler->filter('[data-service="orders"]')->text();
        self::assertStringContainsString('2026-10-20 12:00:00', $card);
        self::assertStringContainsString('80s', $card);
    }

    public function test_a_second_view_within_sixty_seconds_sends_no_new_request(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        $orders = $this->realService('orders');

        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $this->advanceTimeBy(59);
        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());

        self::assertSame('59s', $this->ageOf($client->request('GET', '/'), 'orders'));
    }

    public function test_after_sixty_seconds_a_view_requests_a_new_snapshot(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        $orders = $this->realService('orders');

        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $this->advanceTimeBy(60);
        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());

        $crawler = $client->request('GET', '/');
        self::assertSame('connected', $this->statusOf($crawler, 'orders'));
        self::assertSame('0s', $this->ageOf($crawler, 'orders'));
    }

    public function test_refresh_requests_a_new_snapshot_before_sixty_seconds_pass(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        $orders = $this->realService('orders');

        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $this->advanceTimeBy(20);
        $client->request('POST', '/refresh');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());

        self::assertSame('0s', $this->ageOf($client->request('GET', '/'), 'orders'));
    }

    public function test_a_broker_that_cannot_be_reached_shows_every_service_as_unreachable_with_the_reason(): void
    {
        $this->configurePulseWithServices('orders');
        $this->configurePulse(['PULSE_TRANSPORT_DSN' => 'pgsql://ecotone:secret@127.0.0.1:1/ecotone', 'PULSE_SERVICES' => 'orders:orders_channel']);
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('unreachable', $this->statusOf($crawler, 'orders'));
        self::assertCount(1, $crawler->filter('[data-service="orders"] [data-request-error]'));
        $error = $crawler->filter('[data-service="orders"] [data-request-error]')->text();
        self::assertStringContainsString('ConnectionException', $error);
        self::assertStringNotContainsString('127.0.0.1', $error);
        self::assertStringNotContainsString('Connection refused', $error);
    }

    public function test_refresh_shows_the_service_as_waiting_with_its_previous_snapshot_until_the_reply_arrives(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        $orders = $this->realService('orders');

        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $this->advanceTimeBy(20);
        $client->request('POST', '/refresh');
        $waiting = $client->request('GET', '/');

        self::assertSame('waiting', $this->statusOf($waiting, 'orders'));
        self::assertSame('20s', $this->ageOf($waiting, 'orders'));
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        self::assertSame('connected', $this->statusOf($client->request('GET', '/'), 'orders'));
    }

    public function test_a_view_after_a_request_timed_out_requests_again_without_waiting_for_sixty_seconds(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        [$orders, $handler] = $this->countingService('orders', $this->snapshotJson('orders', '2.0.0'));

        $client->request('GET', '/');
        $this->advanceTimeBy(10);
        self::assertSame('unreachable', $this->statusOf($client->request('GET', '/'), 'orders'));
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $this->runService($orders, 'orders');

        self::assertSame(2, $handler->requests);
        self::assertSame('connected', $this->statusOf($client->request('GET', '/'), 'orders'));
    }

    public function test_views_in_a_row_send_a_single_request(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        [$orders, $handler] = $this->countingService('orders', $this->snapshotJson('orders', '2.0.0'));

        $client->request('GET', '/');
        $client->request('GET', '/');
        $client->request('GET', '/');
        $this->runService($orders, 'orders');

        self::assertSame(1, $handler->requests);
    }

    public function test_a_reply_from_a_service_that_is_not_configured_is_ignored(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        $orders = $this->serviceAnsweringWith('orders', $this->snapshotJson('ghost', '2.0.0'));

        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());

        self::assertSame('waiting', $this->statusOf($client->request('GET', '/'), 'orders'));
    }

    public function test_a_reply_that_is_not_json_does_not_stop_the_worker(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseClient();
        $orders = $this->serviceAnsweringWith('orders', 'not json at all');
        $billing = $this->serviceAnsweringWith('billing', $this->snapshotJson('billing', '2.0.0'));

        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runService($billing, 'billing');
        $this->runPulseWorker($client->getKernel());
        $crawler = $client->request('GET', '/');

        self::assertSame('waiting', $this->statusOf($crawler, 'orders'));
        self::assertSame('connected', $this->statusOf($crawler, 'billing'));
    }

    public function test_a_reply_that_is_not_a_supported_snapshot_is_shown_as_an_error_of_that_service(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        $orders = $this->serviceAnsweringWith('orders', json_encode(['schemaVersion' => 2, 'serviceName' => 'orders'], JSON_THROW_ON_ERROR));

        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $crawler = $client->request('GET', '/');

        self::assertSame('waiting', $this->statusOf($crawler, 'orders'));
        self::assertStringContainsString('schema version 2', $crawler->filter('[data-service="orders"] [data-reply-error]')->text());
    }

    public function test_the_page_refreshes_itself_while_a_service_is_waiting(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filter('meta[http-equiv="refresh"]'));
    }

    public function test_ecotone_version_drift_between_services_is_highlighted(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseClient();
        $orders = $this->serviceAnsweringWith('orders', $this->snapshotJson('orders', '2.0.0'));
        $billing = $this->serviceAnsweringWith('billing', $this->snapshotJson('billing', '2.1.0'));

        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runService($billing, 'billing');
        $this->runPulseWorker($client->getKernel());
        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filter('[data-version-drift]'));
        self::assertCount(1, $crawler->filter('[data-drifted]'));
        self::assertCount(1, $crawler->filter('[data-service="orders"] [data-drifted]'));
    }

    public function test_the_dead_letter_total_is_shown_when_the_snapshot_has_the_section(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseClient();
        $orders = $this->serviceAnsweringWith('orders', $this->snapshotJson('orders', '2.0.0', ['total' => 7, 'groups' => [['messageName' => 'order.place', 'exceptionClass' => 'RuntimeException', 'count' => 7]], 'recent' => []]));
        $billing = $this->serviceAnsweringWith('billing', $this->snapshotJson('billing', '2.0.0'));

        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runService($billing, 'billing');
        $this->runPulseWorker($client->getKernel());
        $crawler = $client->request('GET', '/');

        self::assertSame('7', $crawler->filter('[data-service="orders"] [data-dead-letters]')->text());
        self::assertCount(0, $crawler->filter('[data-service="billing"] [data-dead-letters]'));
        self::assertCount(0, $crawler->filter('[data-version-drift]'));
    }

    public function test_every_page_of_the_navigation_is_reachable(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/');
        $links = $crawler->filter('nav a')->each(static fn (Crawler $link) => [$link->text(), $link->attr('href')]);

        self::assertSame(['Services', 'Dead letters', 'Flow graph', 'Topology', 'Catalogue'], array_column($links, 0));
        foreach (array_column($links, 1) as $href) {
            $client->request('GET', $href);
            self::assertResponseIsSuccessful();
        }
    }

    private function pulseClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function statusOf(Crawler $crawler, string $service): string
    {
        return $crawler->filter(sprintf('[data-service="%s"] [data-status]', $service))->attr('data-status');
    }

    private function ageOf(Crawler $crawler, string $service): string
    {
        return $crawler->filter(sprintf('[data-service="%s"] [data-age]', $service))->text();
    }

    /**
     * @param array<string, mixed>|null $deadLetters
     */
    private function snapshotJson(string $service, string $ecotoneVersion, ?array $deadLetters = null): string
    {
        $snapshot = [
            'schemaVersion' => 1,
            'serviceName' => $service,
            'takenAt' => '2026-10-20T12:00:00+00:00',
            'overview' => ['ecotoneVersion' => $ecotoneVersion, 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []],
            'topology' => ['serviceMaps' => []],
        ];
        if ($deadLetters !== null) {
            $snapshot['deadLetters'] = $deadLetters;
        }

        return json_encode($snapshot, JSON_THROW_ON_ERROR);
    }
}
