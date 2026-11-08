<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\DeadLetterServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class ServiceConsumersTest extends ReleasingWebTestCase
{
    use DeadLetterServicesTrait;

    public function test_each_consumer_lists_its_handlers_and_features_tagged_as_its_own_configuration_or_global(): void
    {
        $crawler = $this->pageOfSnapshot(['consumers' => [
            ['name' => 'orders', 'kind' => 'polling', 'handlers' => ['order.place.endpoint'], 'features' => [
                ['name' => 'Database transaction', 'value' => 'on', 'scope' => 'global', 'handler' => null],
                ['name' => 'Delayed retries', 'value' => '2 retries: after 500 ms and 1000 ms, then no further retry', 'scope' => 'consumer-level config', 'handler' => 'order.place.endpoint'],
            ]],
            ['name' => 'reports', 'kind' => 'inbound', 'handlers' => [], 'features' => [
                ['name' => 'Poller', 'value' => 'every 1 minute', 'scope' => 'consumer-level config', 'handler' => null],
            ]],
        ]]);

        $orders = $crawler->filter('[data-hub-consumers] [data-consumer="orders"]');
        self::assertSame('polling', trim($orders->filter('[data-consumer-kind]')->text()));
        self::assertSame(['order.place.endpoint'], $orders->filter('[data-consumer-handlers] li')->each(static fn (Crawler $handler): string => trim($handler->text())));
        $transaction = $orders->filter('[data-consumer-feature="Database transaction"]');
        self::assertSame('on', trim($transaction->filter('[data-consumer-feature-value]')->text()));
        self::assertSame('global', trim($transaction->filter('[data-consumer-feature-scope]')->text()));
        $delayed = $orders->filter('[data-consumer-feature="Delayed retries"]');
        self::assertSame('consumer-level config', trim($delayed->filter('[data-consumer-feature-scope]')->text()));
        self::assertSame('order.place.endpoint', trim($delayed->filter('[data-consumer-feature-handler]')->text()));
        self::assertSame('inbound', trim($crawler->filter('[data-consumer="reports"] [data-consumer-kind]')->text()));
    }

    public function test_a_service_that_reports_no_consumers_says_so(): void
    {
        $crawler = $this->pageOfSnapshot([]);

        self::assertCount(0, $crawler->filter('[data-hub-consumers] [data-consumer]'));
        self::assertStringContainsString('reports no consumers', $crawler->filter('[data-hub-consumers]')->text());
    }

    /**
     * @param array<string, mixed> $sections
     */
    private function pageOfSnapshot(array $sections): Crawler
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceAnsweringWith('orders', json_encode([
            'schemaVersion' => 1, 'serviceName' => 'orders', 'takenAt' => '2026-10-20T12:00:00+00:00',
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => PHP_VERSION, 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []], 'topology' => ['serviceMaps' => []],
            ...$sections,
        ], JSON_THROW_ON_ERROR));
        $client->request('GET', '/services');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', '/services/orders');
    }
}
