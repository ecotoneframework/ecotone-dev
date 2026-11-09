<?php

declare(strict_types=1);

namespace Tests\Web;

use Tests\TestCase;

/**
 * @internal
 */
final class HomePageTest extends TestCase
{
    public function test_the_home_page_names_the_service_and_links_to_pulse_and_jaeger(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<h1 id="hero-title">Your seat for the night is waiting</h1>', escape: false);
        $response->assertSee('<title>Tickets for live music · Stagefront</title>', escape: false);
        $response->assertSee('<a href="http://localhost:8080">Ecotone Pulse</a>', escape: false);
        $response->assertSee('<a href="http://localhost:16686/search?service=storefront">Jaeger</a>', escape: false);
        $response->assertSee('<a href="http://localhost:8081">Database browser</a>', escape: false);
    }

    public function test_every_page_links_to_the_ticketing_back_office_and_the_database_browser(): void
    {
        foreach (['/', '/concerts', '/whoami'] as $page) {
            $response = $this->get($page);

            $response->assertOk();
            $response->assertSee('<nav class="site-nav site-nav--demo" aria-label="Demo services"><a href="http://localhost:8001/admin">', escape: false);
            $response->assertSee('Ticketing back office</a>', escape: false);
            $response->assertSee('<li><a href="http://localhost:8081">Database</a></li>', escape: false);
        }
    }
}
