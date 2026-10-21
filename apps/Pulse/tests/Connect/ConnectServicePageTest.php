<?php

declare(strict_types=1);

namespace App\Tests\Connect;

use App\Tests\Support\OpenedConnections;
use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class ConnectServicePageTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    public static function frameworks(): iterable
    {
        yield 'lite' => ['lite'];
        yield 'symfony' => ['symfony'];
        yield 'laravel' => ['laravel'];
    }

    public function test_the_connect_page_names_this_pulse_and_the_service(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/connect?service=orders');

        self::assertResponseIsSuccessful();
        $snippet = $crawler->filter('[data-snippet="lite"] [data-php]')->text();
        self::assertStringContainsString("withServiceName('orders')", $snippet);
        self::assertStringContainsString("pulseServiceName: 'ecotone_pulse'", $snippet);
        self::assertStringContainsString("DbalBackedMessageChannelBuilder::create('" . $this->serviceChannels['orders'] . "')", $snippet);
        self::assertStringContainsString("DbalBackedMessageChannelBuilder::create('" . $this->pulseChannel . "')", $snippet);
        self::assertStringContainsString('->withRequestChannel(', $snippet);
        self::assertStringContainsString('orders:' . $this->serviceChannels['orders'], $crawler->filter('[data-pulse-services]')->text());
    }

    public function test_each_framework_tab_says_where_the_class_goes_and_how_the_licence_and_consumer_are_set(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/connect?service=orders');

        self::assertStringContainsString('licenceKey: getenv', $crawler->filter('[data-snippet="lite"] [data-wiring]')->text());
        self::assertStringContainsString('licenceKey', $crawler->filter('[data-snippet="symfony"] [data-wiring]')->text());
        self::assertStringContainsString('%env(ECOTONE_LICENCE_KEY)%', $crawler->filter('[data-snippet="symfony"] [data-wiring]')->text());
        self::assertStringContainsString('bin/console ecotone:run ' . $this->serviceChannels['orders'], $crawler->filter('[data-snippet="symfony"] [data-wiring]')->text());
        self::assertStringContainsString("env('ECOTONE_LICENCE_KEY')", $crawler->filter('[data-snippet="laravel"] [data-wiring]')->text());
        self::assertStringContainsString('php artisan ecotone:run ' . $this->serviceChannels['orders'], $crawler->filter('[data-snippet="laravel"] [data-wiring]')->text());
    }

    #[DataProvider('frameworks')]
    public function test_a_service_booted_from_the_generated_snippet_reaches_connected(string $framework): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        $php = $client->request('GET', '/connect?service=orders')->filter('[data-snippet="' . $framework . '"] [data-php]')->text();
        $class = 'GeneratedPulseSetup' . $framework . uniqid();
        $file = sys_get_temp_dir() . '/' . $class . '.php';
        file_put_contents($file, str_replace('final class PulseSetup', 'final class ' . $class, $php));
        require_once $file;

        $orders = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$class],
            containerOrAvailableServices: [DbalConnectionFactory::class => OpenedConnections::factory($_SERVER['PULSE_TRANSPORT_DSN'])],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $client->request('GET', '/');
        $orders->run($this->serviceChannels['orders'], ExecutionPollingMetadata::createWithTestingSetup());
        $this->runPulseWorker($client->getKernel());

        self::assertSame('connected', $this->statusOf($client->request('GET', '/'), 'orders'));
        unlink($file);
    }

    public function test_the_connect_page_without_a_service_uses_a_placeholder_name(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();

        $crawler = $client->request('GET', '/connect');

        self::assertStringContainsString("withServiceName('my_service')", $crawler->filter('[data-snippet="lite"] [data-php]')->text());
        self::assertStringContainsString('my_service', $crawler->filter('[data-pulse-services]')->text());
    }

    private function pulseClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        return $client;
    }

    private function statusOf(Crawler $crawler, string $service): string
    {
        return $crawler->filter('tr[data-service="' . $service . '"] [data-status]')->attr('data-status');
    }
}
