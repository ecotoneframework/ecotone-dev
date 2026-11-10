<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class NavigationTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    public function test_the_main_navigation_groups_pulse_into_five_sections_that_are_all_reachable(): void
    {
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/services');
        $links = $crawler->filter('nav[data-main-nav] a')->each(static fn (Crawler $link) => [trim($link->text()), $link->attr('href')]);

        self::assertSame(['Overview', 'Services', 'Failed messages', 'Traces', 'Architecture'], array_column($links, 0));
        foreach (array_column($links, 1) as $href) {
            $client->request('GET', $href);
            self::assertResponseIsSuccessful();
        }
    }

    public function test_the_section_of_the_current_page_is_marked(): void
    {
        $client = $this->pulseClient();

        $current = [];
        foreach (['/' => 'overview', '/services' => 'services', '/dead-letters' => 'failures', '/traces' => 'traces', '/flow' => 'architecture', '/topology' => 'architecture', '/catalogue' => 'architecture'] as $path => $section) {
            $marked = $client->request('GET', $path)->filter('nav[data-main-nav] a[aria-current]');
            $current[$path] = $marked->count() === 1 ? $marked->attr('data-nav-section') : null;
        }

        self::assertSame(['/' => 'overview', '/services' => 'services', '/dead-letters' => 'failures', '/traces' => 'traces', '/flow' => 'architecture', '/topology' => 'architecture', '/catalogue' => 'architecture'], $current);
    }

    public function test_architecture_pages_share_tabs_to_the_flow_graph_topology_and_catalogue(): void
    {
        $client = $this->pulseClient();

        foreach (['/flow' => 'flow_graph', '/topology' => 'topology', '/catalogue' => 'catalogue'] as $path => $route) {
            $tabs = $client->request('GET', $path)->filter('nav[data-architecture-tabs] a');

            self::assertSame(['Flow graph', 'Topology', 'Catalogue'], $tabs->each(static fn (Crawler $tab): string => $tab->text()), $path);
            self::assertSame($route, $tabs->filter('[aria-current="page"]')->attr('data-architecture-tab'), $path);
            foreach ($tabs->each(static fn (Crawler $tab): string => $tab->attr('href')) as $href) {
                $client->request('GET', $href);
                self::assertResponseIsSuccessful();
            }
        }
        self::assertCount(0, $client->request('GET', '/traces')->filter('[data-architecture-tabs]'));
    }

    public function test_architecture_tabs_keep_the_service_scope(): void
    {
        $client = $this->pulseClient();
        [$orders] = $this->countingService('orders', json_encode([
            'schemaVersion' => 1,
            'serviceName' => 'orders',
            'takenAt' => '2026-10-20T12:00:00+00:00',
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []],
            'topology' => ['serviceMaps' => []],
        ], JSON_THROW_ON_ERROR));
        $client->request('GET', '/services');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());

        $tabs = $client->request('GET', '/topology?service=orders')->filter('nav[data-architecture-tabs] a');

        self::assertSame(['/flow?service=orders', '/topology?service=orders', '/catalogue?service=orders'], $tabs->each(static fn (Crawler $tab): string => $tab->attr('href')));
    }

    public function test_every_page_offers_to_connect_a_service_beside_the_theme_toggle(): void
    {
        $client = $this->pulseClient();

        foreach (['/', '/services', '/dead-letters', '/flow', '/topology', '/traces', '/catalogue'] as $path) {
            $connect = $client->request('GET', $path)->filter('header.topbar [data-nav-connect]');

            self::assertSame('+ Connect a service', $connect->text(), $path);
            self::assertSame('/connect', $connect->attr('href'), $path);
        }
        $client->request('GET', '/connect');
        self::assertResponseIsSuccessful();
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
