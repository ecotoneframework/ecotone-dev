<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\DeadLetterServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class ServiceHubPageTest extends ReleasingWebTestCase
{
    use DeadLetterServicesTrait;

    public function test_the_hub_connects_health_versions_dead_letters_flow_types_and_the_graph(): void
    {
        $client = $this->pulseWithServices();

        $crawler = $this->freshView($client, '/services/orders');

        self::assertResponseIsSuccessful();
        self::assertSame('connected', $crawler->filter('[data-hub-status]')->attr('data-hub-status'));
        self::assertStringContainsString(PHP_VERSION, $crawler->filter('[data-hub-versions]')->text());
        self::assertSame('/dead-letters?service=orders', $crawler->filter('[data-hub-dead-letters] a')->attr('href'));
        self::assertSame('1', $crawler->filter('[data-hub-dead-letters] [data-total]')->text());
        self::assertStringContainsString('1 failure (2 stored)', preg_replace('/\s+/', ' ', $crawler->filter('[data-hub-dead-letters]')->text()));
        $flowType = $crawler->filter('[data-hub-flow-types] a')->first();
        self::assertSame('order.place', $flowType->text());
        self::assertSame('/traces/orders/order.place', $flowType->attr('href'));
        self::assertSame('/flow?services%5B0%5D=orders', $crawler->filter('[data-hub-graph] a')->attr('href'));
    }

    public function test_the_hub_has_breadcrumbs_back_to_the_services(): void
    {
        $client = $this->pulseWithServices();

        $crawler = $this->freshView($client, '/services/orders');

        $crumbs = $crawler->filter('nav[aria-label="Breadcrumb"] li');
        self::assertSame(['Home', 'orders'], $crumbs->each(static fn (Crawler $crumb) => trim($crumb->text())));
        self::assertSame('/', $crumbs->first()->filter('a')->attr('href'));
    }

    public function test_the_services_table_links_every_service_to_its_hub(): void
    {
        $client = $this->pulseWithServices();

        $crawler = $this->freshView($client, '/services');

        self::assertSame('/services/orders', $crawler->filter('tr[data-service="orders"] a[data-hub-link]')->attr('href'));
    }

    public function test_a_service_without_dead_letter_storage_says_so_on_its_hub(): void
    {
        $client = $this->pulseWithServices();

        $crawler = $this->freshView($client, '/services/billing');

        self::assertStringContainsString('not configured', $crawler->filter('[data-hub-dead-letters]')->text());
        self::assertStringContainsString('withDeadLetter(true)', $crawler->filter('[data-hub-dead-letters]')->text());
    }

    public function test_a_service_that_is_not_in_pulse_services_is_not_found_and_the_message_says_how_to_add_it(): void
    {
        $client = $this->pulseWithServices();

        $client->request('GET', '/services/unknown');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_unreachable_service_shows_a_checklist_from_the_doctor_and_the_snippet_with_this_pulses_names(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $client->request('GET', '/services');
        $this->advanceTimeBy(10);

        $crawler = $client->request('GET', '/services/orders');

        self::assertSame('unreachable', $crawler->filter('[data-hub-status]')->attr('data-hub-status'));
        self::assertSame('ok', $crawler->filter('[data-check^="transport"]')->attr('data-check-status'));
        self::assertStringContainsString('cannot be checked on a database transport', $crawler->filter('[data-check="service orders"]')->text());
        self::assertSame('ok', $crawler->filter('[data-check="licence"]')->attr('data-check-status'));
        self::assertStringContainsString('Enterprise', $crawler->filter('[data-diagnose-list]')->text());
        self::assertStringContainsString("withServiceName('orders')", $crawler->filter('[data-snippet="lite"] [data-php]')->text());
        self::assertStringContainsString("pulseServiceName: 'ecotone_pulse'", $crawler->filter('[data-snippet="lite"] [data-php]')->text());
        self::assertStringContainsString('orders:' . $this->serviceChannels['orders'], $crawler->filter('[data-pulse-services]')->text());
    }

    public function test_the_hub_of_an_unreachable_service_skips_the_probes_that_do_not_fit_the_time_budget_and_still_renders(): void
    {
        $this->configurePulseWithServices('orders');
        $this->configurePulse(['PULSE_SERVICES' => 'orders:' . $this->serviceChannels['orders'], 'PULSE_CHANNEL' => $this->pulseChannel, 'PULSE_DOCTOR_BUDGET_SECONDS' => '0']);
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $client->request('GET', '/services');
        $this->advanceTimeBy(10);

        $crawler = $client->request('GET', '/services/orders');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-checks-skipped]'));
        self::assertStringContainsString('PULSE_DOCTOR_BUDGET_SECONDS', $crawler->filter('[data-checks-skipped]')->text());
        self::assertCount(0, $crawler->filter('[data-check]'));
        self::assertCount(1, $crawler->filter('[data-diagnose-list]'));
        self::assertStringContainsString("withServiceName('orders')", $crawler->filter('[data-snippet="lite"] [data-php]')->text());
    }

    public function test_the_hub_of_an_unreachable_service_checks_only_the_connection_of_that_service(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $client->request('GET', '/services');
        $this->advanceTimeBy(10);

        $crawler = $client->request('GET', '/services/orders');

        self::assertCount(0, $crawler->filter('[data-check="internal database"]'));
        self::assertCount(0, $crawler->filter('[data-check="settings"]'));
    }

    private function pulseWithServices(): KernelBrowser
    {
        $this->configurePulseWithServices('orders', 'billing');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceAnsweringWith('orders', $this->ordersSnapshot());
        $this->services['orders'] = $orders;
        $this->serviceWithoutDeadLetters('billing');

        return $client;
    }

    private function ordersSnapshot(): string
    {
        $entry = static fn (string $id): array => ['messageId' => $id, 'failureId' => 'aaaaaaaaaaaa', 'exceptionFile' => '/srv/Place.php', 'exceptionLine' => 3, 'messageName' => 'order.place', 'failedAt' => gmdate('c'), 'exceptionClass' => 'RuntimeException', 'exceptionMessage' => 'failed', 'stackTrace' => '#0', 'payload' => '{}', 'contentType' => 'application/json', 'channel' => 'orders'];

        return json_encode([
            'schemaVersion' => 1,
            'serviceName' => 'orders',
            'takenAt' => gmdate('c'),
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => PHP_VERSION, 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []],
            'topology' => ['serviceMaps' => []],
            'deadLetter' => ['enabled' => true],
            'deadLetters' => ['total' => 2, 'groups' => [['failureId' => 'aaaaaaaaaaaa', 'messageName' => 'order.place', 'exceptionClass' => 'RuntimeException', 'exceptionFile' => '/srv/app/src/Place.php', 'exceptionLine' => 12, 'exceptionMessage' => 'failed', 'count' => 2, 'firstFailedAt' => gmdate('c'), 'lastFailedAt' => gmdate('c')]], 'recent' => [$entry('a'), $entry('b')]],
            'tracing' => ['statsWindow' => 100, 'flowTypes' => [['flowType' => 'order.place', 'runs' => 3, 'windowRuns' => 3, 'runsWithErrors' => 0, 'p50Ms' => 5, 'p95Ms' => 9, 'maxMs' => 9, 'lastStartedAt' => gmdate('c')]]],
        ], JSON_THROW_ON_ERROR);
    }

    private function freshView(KernelBrowser $client, string $uri): Crawler
    {
        $client->request('POST', '/refresh');
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', $uri);
    }
}
