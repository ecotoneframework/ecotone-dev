<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\DeadLetterServicesTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @internal
 */
final class ServicesTableTest extends WebTestCase
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
}
