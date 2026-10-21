<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\DeadLetterServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;

/**
 * @internal
 */
final class ServicesTableTest extends ReleasingWebTestCase
{
    use DeadLetterServicesTrait;

    public function test_services_are_one_row_each_with_a_status_pill_and_a_summary_strip(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceWithDeadLetters('orders');
        $this->failOrder($orders, 'order.place', 1);
        $this->failOrder($orders, 'order.cancel', 2);
        $this->serviceWithoutDeadLetters('billing');
        $client->request('POST', '/refresh');
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());

        $crawler = $client->request('GET', '/');

        self::assertCount(2, $crawler->filter('table [data-service]'));
        self::assertSame('connected', $crawler->filter('tr[data-service="orders"] .pill[data-status]')->attr('data-status'));
        self::assertSame('2', $crawler->filter('[data-summary="dead-letters"] b')->text());
        self::assertSame('2', $crawler->filter('[data-summary="connected"] b')->text());
        self::assertSame('0', $crawler->filter('[data-summary="unreachable"] b')->text());
        self::assertSame('0', $crawler->filter('[data-summary="drift"] b')->text());
    }

    public function test_the_dead_letter_count_of_a_service_links_to_its_dead_letters(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceWithDeadLetters('orders');
        $this->failOrder($orders, 'order.place', 1);
        $client->request('POST', '/refresh');
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());

        $crawler = $client->request('GET', '/');

        self::assertSame('/dead-letters?service=orders', $crawler->filter('tr[data-service="orders"] [data-dead-letters] a')->attr('href'));
    }

    public function test_an_unreachable_service_links_to_its_diagnosis(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $client->request('GET', '/');
        $this->advanceTimeBy(10);

        $crawler = $client->request('GET', '/');

        self::assertSame('unreachable', $crawler->filter('tr[data-service="orders"] [data-status]')->attr('data-status'));
        self::assertCount(1, $crawler->filter('tr[data-service="orders"] [data-diagnose]'));
        self::assertSame('1', $crawler->filter('[data-summary="unreachable"] b')->text());
    }

    public function test_the_service_behind_in_ecotone_version_is_highlighted_and_counted(): void
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceAnsweringWith('orders', $this->versionSnapshot('orders', '2.0.0'));
        $billing = $this->serviceAnsweringWith('billing', $this->versionSnapshot('billing', '2.1.0'));
        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runService($billing, 'billing');
        $this->runPulseWorker($client->getKernel());

        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filter('tr[data-service="orders"] [data-drifted]'));
        self::assertCount(0, $crawler->filter('tr[data-service="billing"] [data-drifted]'));
        self::assertSame('1', $crawler->filter('[data-summary="drift"] b')->text());
        self::assertStringContainsString('2.0.0, 2.1.0', $crawler->filter('[data-version-drift]')->text());
    }

    private function versionSnapshot(string $service, string $version): string
    {
        return json_encode(['schemaVersion' => 1, 'serviceName' => $service, 'takenAt' => '2026-10-20T12:00:00+00:00', 'overview' => ['ecotoneVersion' => $version, 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]], 'flowGraph' => ['nodes' => [], 'edges' => []], 'topology' => ['serviceMaps' => []]], JSON_THROW_ON_ERROR);
    }
}
