<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class PeriodicRefreshTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    public function test_a_refresh_tick_requests_a_snapshot_from_every_service(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = $this->pulseClient();
        [$orders, $ordersHandler] = $this->countingService('orders', $this->snapshotJson('orders'));
        [$billing, $billingHandler] = $this->countingService('billing', $this->snapshotJson('billing'));

        $this->refreshTick($client);
        $this->runService($orders, 'orders');
        $this->runService($billing, 'billing');

        self::assertSame(1, $ordersHandler->requests);
        self::assertSame(1, $billingHandler->requests);
    }

    public function test_a_view_after_the_replies_to_a_tick_shows_connected_without_sending_new_requests(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        [$orders, $handler] = $this->countingService('orders', $this->snapshotJson('orders'));

        $this->refreshTick($client);
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $this->advanceTimeBy(15);
        $crawler = $client->request('GET', '/services');
        $this->runService($orders, 'orders');

        self::assertSame('connected', $this->statusOf($crawler, 'orders'));
        self::assertSame('15s', $crawler->filter('[data-service="orders"] [data-age]')->text());
        self::assertSame(1, $handler->requests);
    }

    public function test_a_tick_while_a_request_is_outstanding_sends_nothing(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        [$orders, $handler] = $this->countingService('orders', $this->snapshotJson('orders'));

        $this->refreshTick($client);
        $this->advanceTimeBy(9);
        $this->refreshTick($client);
        $this->runService($orders, 'orders');

        self::assertSame(1, $handler->requests);
    }

    public function test_a_tick_after_the_request_timed_out_requests_again(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        [$orders, $handler] = $this->countingService('orders', $this->snapshotJson('orders'));

        $this->refreshTick($client);
        $this->advanceTimeBy(20);
        $this->refreshTick($client);
        $this->runService($orders, 'orders');

        self::assertSame(2, $handler->requests);
    }

    public function test_a_tick_after_a_reply_requests_the_next_snapshot(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        [$orders, $handler] = $this->countingService('orders', $this->snapshotJson('orders'));

        $this->refreshTick($client);
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $this->advanceTimeBy(20);
        $this->refreshTick($client);
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());

        self::assertSame(2, $handler->requests);
        self::assertSame('0s', $client->request('GET', '/services')->filter('[data-service="orders"] [data-age]')->text());
    }

    public function test_a_service_that_never_answers_a_tick_becomes_unreachable_after_the_reply_timeout(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $this->refreshTick($client);
        $this->advanceTimeBy(9);
        self::assertSame('waiting', $this->statusOf($client->request('GET', '/services'), 'orders'));
        $this->advanceTimeBy(1);

        self::assertSame('unreachable', $this->statusOf($client->request('GET', '/services'), 'orders'));
    }

    public function test_a_service_whose_request_timed_out_stays_unreachable_while_the_retry_is_outstanding(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        [$orders, $handler] = $this->countingService('orders', $this->snapshotJson('orders'));

        $this->refreshTick($client);
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        $this->advanceTimeBy(20);
        $this->refreshTick($client);
        $this->advanceTimeBy(10);
        self::assertSame('unreachable', $this->statusOf($client->request('GET', '/services'), 'orders'));
        $this->advanceTimeBy(10);
        $this->refreshTick($client);
        $this->advanceTimeBy(5);
        $crawler = $client->request('GET', '/services');

        self::assertSame('unreachable', $this->statusOf($crawler, 'orders'));
        self::assertStringContainsString('Unreachable — retrying', $crawler->filter('[data-service="orders"] [data-status]')->text());
        self::assertSame('45s', $crawler->filter('[data-service="orders"] [data-age]')->text());
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        self::assertSame('connected', $this->statusOf($client->request('GET', '/services'), 'orders'));
    }

    public function test_a_service_that_never_answered_stays_unreachable_across_ticks(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $this->refreshTick($client, ticks: 4);
        $this->advanceTimeBy(5);

        $crawler = $client->request('GET', '/services');
        self::assertSame('unreachable', $this->statusOf($crawler, 'orders'));
        self::assertStringContainsString('retrying', $crawler->filter('[data-service="orders"] [data-status]')->text());
    }

    public function test_ticks_request_stale_services_and_skip_outstanding_ones(): void
    {
        $this->configurePulseWithServices('orders');
        $this->configurePulse(['PULSE_REPLY_TIMEOUT' => '30', 'PULSE_CHANNEL' => $this->pulseChannel, 'PULSE_SERVICES' => 'orders:' . $this->serviceChannels['orders']]);
        $client = $this->pulseClient();
        [$orders, $handler] = $this->countingService('orders', $this->snapshotJson('orders'));

        $this->refreshTick($client, ticks: 3);
        $this->runService($orders, 'orders');

        self::assertSame(2, $handler->requests);
    }

    public function test_a_broker_send_failure_does_not_stop_the_ticks(): void
    {
        $this->configurePulseWithServices('orders');
        $this->configurePulse(['PULSE_TRANSPORT_DSN' => 'pgsql://ecotone:secret@127.0.0.1:1/ecotone', 'PULSE_SERVICES' => 'orders:orders_channel']);
        $client = static::createClient();
        $this->setUpPulseInternalStorage($client->getKernel());
        $client->disableReboot();

        $this->refreshTick($client, ticks: 3);

        $crawler = $client->request('GET', '/services');
        self::assertCount(1, $crawler->filter('[data-service="orders"] [data-request-error]'));
    }

    private function refreshTick(KernelBrowser $client, int $ticks = 1): void
    {
        $application = new Application($client->getKernel());
        $command = new CommandTester($application->find('pulse:refresh'));
        $command->execute(['--ticks' => $ticks]);
        $command->assertCommandIsSuccessful();
    }

    private function pulseClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        return $client;
    }

    private function snapshotJson(string $service): string
    {
        return json_encode([
            'schemaVersion' => 1,
            'serviceName' => $service,
            'takenAt' => '2026-10-20T12:00:00+00:00',
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []],
            'topology' => ['serviceMaps' => []],
        ], JSON_THROW_ON_ERROR);
    }
}
