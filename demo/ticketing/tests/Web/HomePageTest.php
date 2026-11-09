<?php

declare(strict_types=1);

namespace App\Tests\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @internal
 */
final class HomePageTest extends WebTestCase
{
    public function test_the_home_page_names_the_service_and_links_to_pulse_and_jaeger(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ticketing');
        self::assertSame('http://localhost:8080', $crawler->selectLink('Ecotone Pulse')->attr('href'));
        self::assertSame('http://localhost:16686/search?service=ticketing', $crawler->selectLink('Jaeger')->attr('href'));
    }

    public function test_the_home_page_opens_the_database_browser_and_the_storefront(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');

        self::assertSame('http://localhost:8081', $crawler->selectLink('Browse the databases')->attr('href'));
        self::assertSame('http://localhost:8000', $crawler->selectLink('Open the storefront')->attr('href'));
    }

    public function test_every_back_office_page_links_to_the_storefront_pulse_jaeger_and_the_database_browser(): void
    {
        $client = static::createClient();

        foreach (['/', '/admin/concerts', '/admin/venues'] as $page) {
            $links = $client->request('GET', $page)->filter('nav.app-nav--demo a');

            self::assertSame(
                ['Storefront' => 'http://localhost:8000', 'Ecotone Pulse' => 'http://localhost:8080', 'Jaeger' => 'http://localhost:16686/search?service=ticketing', 'Database' => 'http://localhost:8081'],
                array_combine($links->each(static fn ($link): string => trim($link->text())), $links->each(static fn ($link): string => $link->attr('href'))),
            );
        }
    }

    public function test_the_home_page_opens_the_back_office(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');

        self::assertSame('/admin', $crawler->selectLink('Open the back office')->attr('href'));
    }
}
