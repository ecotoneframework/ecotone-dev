<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class ChannelDepthTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    public function test_the_hub_lists_every_channel_with_its_depth_and_a_level_against_the_thresholds(): void
    {
        $this->configurePulseWithServices('orders');
        $this->configurePulse([
            ...$this->currentEnvironment(),
            'PULSE_QUEUE_WARNING' => 'orders:invoices=500,*=1000',
            'PULSE_QUEUE_CRITICAL' => 'orders:invoices=800,*=5000',
        ]);
        $client = $this->clientAnswering($this->channels());

        $crawler = $client->request('GET', '/services/orders');

        $rows = [];
        foreach (['invoices', 'emails', 'bulk', 'small'] as $channel) {
            $row = $crawler->filter(sprintf('[data-hub-channels] tr[data-channel="%s"]', $channel));
            $rows[$channel] = [$row->filter('[data-ready]')->text(), $row->filter('[data-level]')->attr('data-level')];
        }
        self::assertSame(['invoices' => ['600', 'warning'], 'emails' => ['1,500', 'warning'], 'bulk' => ['6,000', 'critical'], 'small' => ['5', 'ok']], $rows);
    }

    public function test_the_consumers_column_counts_consumers_where_the_broker_reports_them_and_says_in_flight_where_it_does_not(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->clientAnswering($this->channels());

        $crawler = $client->request('GET', '/services/orders');

        self::assertSame('2', $crawler->filter('tr[data-channel="invoices"] [data-consumers]')->text());
        self::assertSame('7 in flight', $crawler->filter('tr[data-channel="emails"] [data-consumers]')->text());
        self::assertSame('–', $crawler->filter('tr[data-channel="small"] [data-consumers]')->text());
        self::assertSame('3', $crawler->filter('tr[data-channel="emails"] [data-delayed]')->text());
    }

    public function test_a_channel_that_could_not_be_measured_shows_why(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->clientAnswering($this->channels());

        $crawler = $client->request('GET', '/services/orders');

        $row = $crawler->filter('tr[data-channel="ghost"]');
        self::assertStringContainsString("queue 'ghost' does not exist", $row->filter('[data-probe-error]')->text());
        self::assertSame('unknown', $row->filter('[data-level]')->attr('data-level'));
    }

    public function test_the_services_table_counts_the_channels_over_a_threshold_and_links_to_them(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->clientAnswering($this->channels());

        $crawler = $client->request('GET', '/');

        $badge = $crawler->filter('tr[data-service="orders"] [data-channels-over]');
        self::assertSame('2', $badge->text());
        self::assertSame('/services/orders#channels', $badge->filter('a')->attr('href'));
    }

    public function test_a_service_with_no_channel_over_a_threshold_shows_none_over(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->clientAnswering([['name' => 'small', 'kind' => 'queue', 'ready' => 5, 'delayed' => 0, 'inFlight' => 0, 'consumers' => null, 'probeError' => null]]);

        $crawler = $client->request('GET', '/');

        self::assertSame('0', $crawler->filter('tr[data-service="orders"] [data-channels-over]')->text());
    }

    public function test_the_doctor_lists_the_effective_thresholds(): void
    {
        $this->configurePulseWithServices('orders');
        $this->configurePulse([
            ...$this->currentEnvironment(),
            'PULSE_QUEUE_WARNING' => 'orders:invoices=500,*=1000',
            'PULSE_QUEUE_CRITICAL' => '8000',
        ]);
        $command = new CommandTester((new Application(self::bootKernel()))->find('pulse:doctor'));
        $command->execute(['--format' => 'json']);

        $checks = [];
        foreach (json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['checks'] as $check) {
            $checks[$check['name']] = $check;
        }

        self::assertSame('info', $checks['queue thresholds']['status']);
        self::assertSame('warning: orders:invoices=500, *=1000; critical: 8000', $checks['queue thresholds']['message']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channels(): array
    {
        return [
            ['name' => 'invoices', 'kind' => 'async', 'ready' => 600, 'delayed' => 0, 'inFlight' => null, 'consumers' => 2, 'probeError' => null],
            ['name' => 'emails', 'kind' => 'async', 'ready' => 1500, 'delayed' => 3, 'inFlight' => 7, 'consumers' => null, 'probeError' => null],
            ['name' => 'bulk', 'kind' => 'queue', 'ready' => 6000, 'delayed' => 0, 'inFlight' => 0, 'consumers' => null, 'probeError' => null],
            ['name' => 'small', 'kind' => 'queue', 'ready' => 5, 'delayed' => null, 'inFlight' => null, 'consumers' => null, 'probeError' => null],
            ['name' => 'ghost', 'kind' => 'queue', 'ready' => null, 'delayed' => null, 'inFlight' => null, 'consumers' => null, 'probeError' => "RabbitMQ queue 'ghost' does not exist"],
        ];
    }

    /**
     * @param list<array<string, mixed>> $channels
     */
    private function clientAnswering(array $channels): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceAnsweringWith('orders', json_encode([
            'schemaVersion' => 1,
            'serviceName' => 'orders',
            'takenAt' => '2026-10-20T12:00:00+00:00',
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []],
            'topology' => ['serviceMaps' => []],
            'channels' => $channels,
        ], JSON_THROW_ON_ERROR));
        $client->request('GET', '/');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());

        return $client;
    }

    /**
     * @return array<string, string>
     */
    private function currentEnvironment(): array
    {
        $environment = [];
        foreach (['ECOTONE_LICENCE_KEY', 'PULSE_SERVICE_NAME', 'PULSE_CHANNEL', 'PULSE_TRANSPORT_DSN', 'PULSE_SERVICES'] as $name) {
            $environment[$name] = $_SERVER[$name];
        }

        return $environment;
    }
}
