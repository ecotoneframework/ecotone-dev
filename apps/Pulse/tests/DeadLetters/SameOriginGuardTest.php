<?php

declare(strict_types=1);

namespace App\Tests\DeadLetters;

use App\Tests\Support\DeadLetterServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
final class SameOriginGuardTest extends ReleasingWebTestCase
{
    use DeadLetterServicesTrait;

    private const DELETE_EVERYTHING_OF_ORDERS = ['action' => 'delete', 'scope' => 'filter', 'service' => 'orders', 'exceptionClass' => 'RuntimeException', 'confirm' => '1'];

    public function test_a_post_from_another_origin_is_refused_and_deletes_nothing(): void
    {
        $client = $this->pulseWithFailures();

        $client->request('POST', '/dead-letters/actions', self::DELETE_EVERYTHING_OF_ORDERS, [], ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('Origin', $client->getResponse()->getContent());
        self::assertSame(2, $this->storedDeadLetters($client));
    }

    public function test_a_post_marked_cross_site_by_the_browser_is_refused(): void
    {
        $client = $this->pulseWithFailures();

        $client->request('POST', '/dead-letters/actions', self::DELETE_EVERYTHING_OF_ORDERS, [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('Sec-Fetch-Site', $client->getResponse()->getContent());
        self::assertSame(2, $this->storedDeadLetters($client));
    }

    public function test_refresh_is_guarded_the_same_way(): void
    {
        $client = $this->pulseWithFailures();

        $client->request('POST', '/refresh', [], [], ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_same_origin_post_is_served(): void
    {
        $client = $this->pulseWithFailures();

        $client->request('POST', '/dead-letters/actions', self::DELETE_EVERYTHING_OF_ORDERS, [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->deliver($client);

        self::assertResponseRedirects();
        self::assertSame(0, $this->storedDeadLetters($client));
    }

    public function test_the_fetch_site_header_decides_alone_when_it_is_present(): void
    {
        $client = $this->pulseWithFailures();

        $client->request('POST', '/refresh', [], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ORIGIN' => 'https://pulse.internal.example']);

        self::assertResponseRedirects();
    }

    public function test_a_cross_origin_post_without_the_fetch_site_header_is_refused(): void
    {
        $client = $this->pulseWithFailures();

        $client->request('POST', '/refresh', [], [], ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_host_forwarded_by_a_trusted_proxy_is_the_host_the_origin_is_compared_with(): void
    {
        $this->trustProxy('127.0.0.1');
        $client = $this->pulseWithFailures();

        $client->request('POST', '/refresh', [], [], ['HTTP_X_FORWARDED_HOST' => 'pulse.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_ORIGIN' => 'https://pulse.example.com']);
        self::assertResponseRedirects();

        $client->request('POST', '/refresh', [], [], ['HTTP_X_FORWARDED_HOST' => 'pulse.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_forwarded_host_from_a_proxy_that_is_not_trusted_is_ignored(): void
    {
        $client = $this->pulseWithFailures();

        $client->request('POST', '/refresh', [], [], ['HTTP_X_FORWARDED_HOST' => 'pulse.example.com', 'HTTP_ORIGIN' => 'http://pulse.example.com']);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_post_without_browser_headers_is_served(): void
    {
        $client = $this->pulseWithFailures();

        $client->request('POST', '/refresh');

        self::assertResponseRedirects();
    }

    private function trustProxy(string $address): void
    {
        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $address;
        putenv('TRUSTED_PROXIES=' . $address);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
        putenv('TRUSTED_PROXIES');
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    private function pulseWithFailures(): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceWithDeadLetters('orders');
        $this->failOrder($orders, 'order.place', 1);
        $this->failOrder($orders, 'order.place', 2);
        $client->request('POST', '/refresh');
        $this->deliver($client);

        return $client;
    }

    private function deliver(KernelBrowser $client): void
    {
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());
        $client->request('POST', '/refresh');
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());
    }

    private function storedDeadLetters(KernelBrowser $client): int
    {
        $this->deliver($client);

        return (int) $client->request('GET', '/dead-letters')->filter('[data-service-total="orders"]')->text();
    }
}
