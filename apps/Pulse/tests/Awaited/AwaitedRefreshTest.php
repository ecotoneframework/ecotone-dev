<?php

declare(strict_types=1);

namespace App\Tests\Awaited;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use App\Tests\Support\RespondingWhileWaitingTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class AwaitedRefreshTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;
    use RespondingWhileWaitingTrait;

    public function test_a_refresh_renders_the_fresh_snapshot_in_the_same_response_when_the_service_answers(): void
    {
        $this->configurePulseWithServices('orders');
        $this->awaitRepliesFor(30);
        $client = $this->pulseClient();
        $this->respondWhileWaiting($client->getKernel(), ['orders' => $this->realService('orders')]);

        $client->request('POST', '/refresh');
        $crawler = $client->followRedirect();

        self::assertSame('connected', $this->statusOf($crawler, 'orders'));
        self::assertCount(0, $crawler->filter('meta[http-equiv="refresh"]'));
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
}
