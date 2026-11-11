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

    public function test_the_sidebar_lists_seven_sections_that_are_all_reachable(): void
    {
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/services');
        $links = $crawler->filter('nav[data-main-nav] a[data-nav-section]')->each(static fn (Crawler $link) => [trim($link->text()), $link->attr('href')]);

        self::assertSame(['Overview', 'Services', 'Failed messages', 'Traces', 'Catalogue', 'Flow graph', 'Topology'], array_column($links, 0));
        foreach (array_column($links, 1) as $href) {
            $client->request('GET', $href);
            self::assertResponseIsSuccessful();
        }
    }

    public function test_the_section_of_the_current_page_is_marked(): void
    {
        $client = $this->pulseClient();

        $current = [];
        foreach (['/' => 'overview', '/services' => 'services', '/dead-letters' => 'failures', '/traces' => 'traces', '/flow' => 'flow', '/topology' => 'topology', '/catalogue' => 'catalogue', '/search?q=nothing' => 'catalogue'] as $path => $section) {
            $marked = $client->request('GET', $path)->filter('nav[data-main-nav] a[data-nav-section][aria-current]');
            $current[$path] = $marked->count() === 1 ? $marked->attr('data-nav-section') : null;
        }

        self::assertSame(['/' => 'overview', '/services' => 'services', '/dead-letters' => 'failures', '/traces' => 'traces', '/flow' => 'flow', '/topology' => 'topology', '/catalogue' => 'catalogue', '/search?q=nothing' => 'catalogue'], $current);
    }

    public function test_the_sidebar_lists_every_configured_service_with_a_link_to_its_page(): void
    {
        $client = $this->pulseClient();

        $services = $client->request('GET', '/')->filter('nav[data-main-nav] [data-nav-services] a[data-nav-service]');

        self::assertSame(['orders'], $services->each(static fn (Crawler $link): string => $link->attr('data-nav-service')));
        self::assertSame('/services/orders', $services->attr('href'));
        self::assertSame('page', $client->request('GET', '/services/orders')->filter('[data-nav-service="orders"]')->attr('aria-current'));
    }

    public function test_the_sidebar_offers_each_kind_of_message_and_marks_the_one_shown(): void
    {
        $client = $this->pulseClient();

        $kinds = $client->request('GET', '/catalogue?kind=event')->filter('nav[data-main-nav] [data-nav-kinds] a');

        self::assertSame(['/catalogue?kind=command', '/catalogue?kind=event', '/catalogue?kind=query'], $kinds->each(static fn (Crawler $link): string => $link->attr('href')));
        self::assertSame('event', $kinds->filter('[aria-current="page"]')->attr('data-nav-kind'));
    }

    public function test_the_sidebar_keeps_the_service_scope_between_catalogue_flow_graph_and_topology(): void
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

        $page = $client->request('GET', '/topology?service=orders');

        self::assertSame(
            ['/catalogue?service=orders', '/flow?service=orders', '/topology?service=orders'],
            array_map(static fn (string $section): string => $page->filter('nav[data-main-nav] a[data-nav-section="' . $section . '"]')->attr('href'), ['catalogue', 'flow', 'topology']),
        );
        self::assertSame('/traces', $page->filter('nav[data-main-nav] a[data-nav-section="traces"]')->attr('href'));
    }

    public function test_every_page_offers_a_search_over_every_node_in_the_top_bar(): void
    {
        $client = $this->pulseClient();

        foreach (['/', '/services', '/dead-letters', '/flow', '/topology', '/traces', '/catalogue'] as $path) {
            $search = $client->request('GET', $path)->filter('header.topbar form[data-global-search]');

            self::assertSame('/search', $search->attr('action'), $path);
            self::assertNotSame('', trim((string) $search->filter('input[name="q"]')->attr('aria-label')), $path);
        }
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
