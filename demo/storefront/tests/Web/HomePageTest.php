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
        $response->assertSee('<h1>Storefront</h1>', escape: false);
        $response->assertSee('<a href="http://localhost:8080">Ecotone Pulse</a>', escape: false);
        $response->assertSee('<a href="http://localhost:16686/search?service=storefront">Jaeger</a>', escape: false);
    }
}
