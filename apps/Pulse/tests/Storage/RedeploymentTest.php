<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Tests\Support\PulseServicesTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class RedeploymentTest extends WebTestCase
{
    use PulseServicesTrait;

    public function test_a_restart_keeps_the_snapshots_and_a_redeployment_starts_empty(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        $orders = $this->realService('orders');
        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());
        self::assertSame('connected', $this->statusOf($client->request('GET', '/'), 'orders'));

        self::ensureKernelShutdown();
        $restarted = $this->pulseClient();
        self::assertSame('connected', $this->statusOf($restarted->request('GET', '/'), 'orders'));

        $this->useNewInternalDatabase();
        self::ensureKernelShutdown();
        $redeployed = $this->pulseClient();
        self::assertSame('waiting', $this->statusOf($redeployed->request('GET', '/'), 'orders'));
    }

    private function useNewInternalDatabase(): void
    {
        $path = sys_get_temp_dir() . '/pulse-internal-' . uniqid() . '.sqlite';
        $_SERVER['PULSE_INTERNAL_DATABASE_PATH'] = $_ENV['PULSE_INTERNAL_DATABASE_PATH'] = $path;
        putenv('PULSE_INTERNAL_DATABASE_PATH=' . $path);
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
