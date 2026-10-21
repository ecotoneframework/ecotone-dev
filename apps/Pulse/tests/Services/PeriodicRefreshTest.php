<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\PulseServicesTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class PeriodicRefreshTest extends WebTestCase
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
        $crawler = $client->request('GET', '/');
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
        self::assertSame('0s', $client->request('GET', '/')->filter('[data-service="orders"] [data-age]')->text());
    }

    public function test_a_service_that_never_answers_a_tick_becomes_unreachable_after_the_reply_timeout(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $this->refreshTick($client);
        $this->advanceTimeBy(9);
        self::assertSame('waiting', $this->statusOf($client->request('GET', '/'), 'orders'));
        $this->advanceTimeBy(1);

        self::assertSame('unreachable', $this->statusOf($client->request('GET', '/'), 'orders'));
    }

    private function refreshTick(KernelBrowser $client): void
    {
        $application = new Application($client->getKernel());
        $command = new CommandTester($application->find('pulse:refresh'));
        $command->execute(['--once' => true]);
        $command->assertCommandIsSuccessful();
    }

    private function pulseClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        return $client;
    }

    private function statusOf(Crawler $crawler, string $service): string
    {
        return $crawler->filter(sprintf('[data-service="%s"] [data-status]', $service))->attr('data-status');
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
