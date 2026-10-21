<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\PulseServicesTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @internal
 */
final class HealthTest extends WebTestCase
{
    use PulseServicesTrait;

    public function test_healthz_answers_ok_without_asking_any_service(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();

        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $client->getResponse()->getContent());
    }
}
