<?php

declare(strict_types=1);

namespace App\Tests\DeadLetters;

use App\Tests\Support\DeadLetterServicesTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class DeadLetterDetailTest extends WebTestCase
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
