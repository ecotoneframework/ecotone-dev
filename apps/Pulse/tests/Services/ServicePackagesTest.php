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
final class ServicePackagesTest extends ReleasingWebTestCase
{
    use DeadLetterServicesTrait;

    public function test_the_configuration_view_lists_every_installed_ecotone_package_and_names_the_package_of_each_table(): void
    {
        $crawler = $this->servicePage();

        $packages = $crawler->filter('[data-hub-setup] [data-package]');
        self::assertSame(['core', 'dbal', 'test'], $packages->each(static fn (Crawler $package): string => (string) $package->attr('data-package')));
        self::assertSame('ecotone/dbal', trim($crawler->filter('[data-package="dbal"] [data-package-name]')->text()));
        self::assertSame('dbal', $crawler->filter('[data-hub-setup] [data-setup-item="table:dead_letter"]')->attr('data-setup-package'));
        self::assertCount(0, $crawler->filter('[data-hub-setup] [data-setup-item][data-setup-package="core"]'));
    }

    public function test_each_feature_shows_whether_it_is_on_its_settings_and_a_hint_with_the_call_that_changes_it(): void
    {
        $crawler = $this->servicePage();

        $retries = $crawler->filter('[data-package="core"] [data-feature="Instant retries on asynchronous endpoints"]');
        self::assertSame('true', $retries->attr('data-feature-enabled'));
        self::assertSame('On', trim($retries->filter('[data-feature-status]')->text()));
        self::assertSame('3', trim($retries->filter('[data-feature-setting="Retry times"]')->text()));
        self::assertStringContainsString('tried again right away up to 3 more times (4 attempts in total)', $retries->filter('[data-hint]')->text());
        self::assertStringStartsWith('InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(', trim($retries->filter('[data-feature-configuration]')->text()));
        $commandBus = $crawler->filter('[data-package="core"] [data-feature="Instant retries on the command bus"]');
        self::assertSame('true', $commandBus->attr('data-feature-enabled'));
        self::assertSame('by default, to commands handled in a database transaction', trim($commandBus->filter('[data-feature-setting="Applied"]')->text()));
        $repositories = $crawler->filter('[data-package="dbal"] [data-feature="Doctrine ORM repositories"]');
        self::assertSame('false', $repositories->attr('data-feature-enabled'));
        self::assertSame('Off', trim($repositories->filter('[data-feature-status]')->text()));
        self::assertSame('on', trim($crawler->filter('[data-package="dbal"] [data-feature="Database transactions"] [data-feature-setting="Command bus"]')->text()));
    }

    public function test_a_feature_lists_the_handlers_that_use_its_attribute(): void
    {
        $crawler = $this->pageOfSnapshot([
            'packages' => [['name' => 'core', 'composerPackage' => 'ecotone/ecotone', 'version' => '2.0.0', 'features' => [[
                'name' => 'Distributed Bus', 'enabled' => true, 'settings' => ['Distributed handlers' => '2'],
                'handlers' => ['order.place → place', 'billing.invoiced → whenInvoiced'],
                'hint' => 'Other services can send this service the 2 commands and events of the handlers listed here.', 'configuration' => '#[Distributed]',
            ]]]],
        ]);

        $distributed = $crawler->filter('[data-package="core"] [data-feature="Distributed Bus"]');
        self::assertSame(['order.place → place', 'billing.invoiced → whenInvoiced'], $distributed->filter('[data-feature-handlers] li')->each(static fn (Crawler $handler): string => trim($handler->text())));
        self::assertSame('2.0.0', trim($crawler->filter('[data-package="core"] [data-package-version]')->text()));
    }

    public function test_a_service_that_reports_no_packages_still_shows_its_tables(): void
    {
        $crawler = $this->pageOfSnapshot([
            'setup' => ['tables' => [['feature' => 'dead_letter', 'connection' => 'default', 'initialized' => true, 'error' => null]]],
        ]);

        self::assertCount(0, $crawler->filter('[data-hub-setup] [data-package]'));
        self::assertCount(1, $crawler->filter('[data-hub-setup] [data-setup-item="table:dead_letter"]'));
    }

    /**
     * @param array<string, mixed> $sections
     */
    private function pageOfSnapshot(array $sections): Crawler
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceAnsweringWith('orders', json_encode([
            'schemaVersion' => 1, 'serviceName' => 'orders', 'takenAt' => '2026-10-20T12:00:00+00:00',
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => PHP_VERSION, 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []], 'topology' => ['serviceMaps' => []],
            ...$sections,
        ], JSON_THROW_ON_ERROR));
        $client->request('GET', '/services');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());

        return $this->hubOf($client);
    }

    private function servicePage(): Crawler
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->serviceWithDeadLetters('orders');
        $client->request('POST', '/refresh');
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());

        return $this->hubOf($client);
    }

    private function hubOf(KernelBrowser $client): Crawler
    {
        return $client->request('GET', '/services/orders/configuration');
    }
}
