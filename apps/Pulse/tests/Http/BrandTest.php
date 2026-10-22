<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @internal
 */
final class BrandTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    public function test_every_page_shows_the_logo_and_the_title_linking_home_at_the_start_of_the_header(): void
    {
        $client = $this->pulseClient();

        foreach (['/', '/services', '/dead-letters', '/flow', '/topology', '/traces', '/connect', '/catalogue'] as $path) {
            $crawler = $client->request('GET', $path);

            $brand = $crawler->filter('header.topbar > a.brand');
            self::assertCount(1, $brand, $path);
            self::assertSame($client->getRequest()->getSchemeAndHttpHost() . '/', $brand->link()->getUri(), $path);
            self::assertSame('Ecotone Pulse', trim($brand->filter('.brand-title')->text()), $path);
            self::assertCount(1, $brand->filter('svg[aria-hidden="true"]'), $path);
            self::assertCount(0, $brand->filter('svg[aria-label], svg[role]'), $path);
            self::assertSame('Ecotone Pulse', trim(preg_replace('/\s+/', ' ', $brand->text())), $path);
        }
    }

    public function test_every_page_carries_the_configured_version(): void
    {
        $client = $this->pulseClient();

        foreach (['/', '/services', '/flow'] as $path) {
            $crawler = $client->request('GET', $path);

            self::assertSame('test', $crawler->filter('html')->attr('data-pulse-version'), $path);
        }
    }

    public function test_the_splash_carries_the_logo_and_a_heart(): void
    {
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filter('[data-intro] svg[aria-label="Ecotone"]'));
        self::assertCount(1, $crawler->filter('[data-intro] [data-intro-heart]'));
    }

    private function pulseClient(): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        return $client;
    }
}
