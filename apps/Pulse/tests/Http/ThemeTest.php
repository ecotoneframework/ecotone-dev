<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @internal
 */
final class ThemeTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    public function test_every_page_offers_a_theme_toggle_and_applies_the_stored_theme_before_it_paints(): void
    {
        $client = $this->pulseClient();

        foreach (['/', '/dead-letters', '/flow', '/topology', '/traces', '/connect', '/catalogue'] as $path) {
            $crawler = $client->request('GET', $path);

            self::assertCount(1, $crawler->filter('button[data-theme-toggle]'), $path);
            $head = $crawler->filter('head script')->first()->text('', false);
            self::assertStringContainsString("localStorage.getItem('pulse-theme')", $head, $path);
            self::assertStringContainsString('documentElement.dataset.theme', $head, $path);
        }
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
