<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;

/**
 * @internal
 */
final class ErrorPageTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    public function test_a_page_pulse_does_not_have_is_answered_inside_pulse_with_the_reason_and_ways_out(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient(['debug' => false]);

        $page = $client->request('GET', '/no-such-page');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('404', $page->filter('[data-error-page]')->attr('data-error-page'));
        self::assertNotSame('', trim($page->filter('[data-error-message]')->text()));
        self::assertSame('/search', $page->filter('header.topbar form[data-global-search]')->attr('action'));
        self::assertGreaterThan(0, $page->filter('main a[href="/catalogue"]')->count());
    }
}
