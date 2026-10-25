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

    public function test_the_home_page_links_to_the_admin_pages(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/');

        self::assertSame('/admin', $crawler->selectLink('Admin')->attr('href'));
    }
}
