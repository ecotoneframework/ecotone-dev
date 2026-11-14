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
final class ServiceOverviewTest extends ReleasingWebTestCase
{
    use DeadLetterServicesTrait;

    public function test_the_overview_opens_with_one_verdict_that_carries_the_health_and_the_versions(): void
    {
        $client = $this->pulseAnswering($this->snapshot());

        $crawler = $client->request('GET', '/services/orders');

        $verdict = $crawler->filter('[data-verdict]');
        self::assertCount(1, $verdict);
        self::assertSame('warning', $verdict->attr('data-verdict'));
        self::assertSame('orders is connected; 1 problem needs attention.', $verdict->filter('[data-verdict-sentence]')->text());
        self::assertSame('connected', $verdict->filter('[data-hub-status]')->attr('data-hub-status'));
        self::assertStringContainsString('PHP 8.5.3', $verdict->filter('[data-hub-versions]')->text());
        self::assertStringContainsString('Ecotone 2.0.0', $verdict->filter('[data-hub-versions]')->text());
        $html = (string) $client->getResponse()->getContent();
        self::assertLessThan(strpos($html, '<details class="section'), strpos($html, 'data-verdict='));
        self::assertLessThan(strpos($html, 'data-tile-kind='), strpos($html, 'data-verdict='));
    }

    public function test_an_overview_without_problems_says_all_clear_and_names_what_it_checked(): void
    {
        $client = $this->pulseAnswering($this->snapshot(['deadLetters' => ['total' => 0, 'groups' => [], 'recent' => []]]));

        $crawler = $client->request('GET', '/services/orders');

        self::assertSame('ok', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('All clear: 2 channels, no failed messages, no projections.', $crawler->filter('[data-verdict-sentence]')->text());
        self::assertCount(1, $crawler->filter('[data-hub-problems] [data-all-clear]'));
        self::assertCount(0, $crawler->filter('[data-problem]'));
    }

    public function test_a_failed_message_is_a_problem_card_that_says_which_message_failed_where_and_offers_to_open_the_failure(): void
    {
        $client = $this->pulseAnswering($this->snapshot());

        $crawler = $client->request('GET', '/services/orders');

        $card = $crawler->filter('[data-hub-problems] [data-failure="f-charge"] [data-problem="dead-letters"]');
        self::assertSame('2 ChargeCard messages failed after their retries and are kept until you replay or delete them.', $card->filter('[data-problem-text]')->text());
        self::assertSame(['orders', 'Checkout.php:35'], $card->filter('[data-problem-where] li')->each(static fn (Crawler $place): string => $place->text()));
        self::assertSame('App\Payment\ChargeCard', $card->filter('[data-problem-text] [title]')->attr('title'));
        self::assertStringContainsString('PaymentProviderUnavailable: provider timed out', $card->filter('[data-problem-evidence]')->text());
        self::assertSame('Open the 2 failed messages', $card->filter('.pri')->text());
        self::assertSame('/dead-letters?service=orders&failureId=f-charge', $card->filter('.pri')->attr('href'));
        self::assertCount(1, $card->filter('[data-concept="dead-letter"]'));
    }

    public function test_the_overview_shows_four_summary_tiles_each_linking_to_the_view_it_counts(): void
    {
        $client = $this->pulseAnswering($this->snapshot());

        $crawler = $client->request('GET', '/services/orders');

        $tiles = $crawler->filter('[data-tile-kind]');
        self::assertSame(['channels', 'dead-letters', 'projections', 'traces'], $tiles->each(static fn (Crawler $tile): string => (string) $tile->attr('data-tile-kind')));
        self::assertSame(['/services/orders/channels', '/dead-letters?service=orders', '/services/orders/projections', '/traces?service=orders'], $tiles->each(static fn (Crawler $tile): string => (string) $tile->filter('a.tile-link')->attr('href')));
        $deadLetters = $crawler->filter('[data-hub-dead-letters]');
        self::assertSame('2', $deadLetters->filter('a.tile-link b')->text());
        self::assertSame('in 1 failure group', $deadLetters->filter('[data-tile-state]')->text());
        $traces = $crawler->filter('[data-tile-kind="traces"]');
        self::assertSame('2', $traces->filter('a.tile-link b')->text());
        self::assertSame('no errors recorded', $traces->filter('[data-tile-state]')->text());
        self::assertSame('slowest: order.place, 95% within 40 ms', $traces->filter('[data-tile-detail]')->text());
    }

    public function test_a_channel_whose_depth_is_not_reported_says_why_on_its_tile(): void
    {
        $client = $this->pulseAnswering($this->snapshot());

        $crawler = $client->request('GET', '/services/orders');

        $channels = $crawler->filter('[data-tile-kind="channels"]');
        self::assertSame('ok', $channels->attr('data-tile-status'));
        self::assertSame('none stuck', $channels->filter('[data-tile-state]')->text());
        self::assertSame('orders_async: its transport does not report queue size, which is normal and not a fault', $channels->filter('[data-tile-detail]')->text());
    }

    public function test_how_messages_move_is_collapsed_with_a_count_while_every_channel_is_fine(): void
    {
        $client = $this->pulseAnswering($this->snapshot());

        $crawler = $client->request('GET', '/services/orders');

        $section = $crawler->filter('details[data-section="hub.orders.flow"]');
        self::assertNull($section->attr('open'));
        self::assertSame('2 channels · none stuck', $section->filter('summary [data-summary-state]')->text());
        $async = $section->filter('[data-flow-channel="orders_async"]');
        self::assertSame(['orders_async', 'ecotone:run orders_async', '2 handlers'], $async->filter('li')->each(static fn (Crawler $step): string => trim($step->text())));
        self::assertCount(1, $section->filter('[data-concept="consumer"]'));
    }

    public function test_how_messages_move_opens_when_a_channel_is_stuck(): void
    {
        $this->configurePulseWithServices('orders');
        $this->configurePulse([...$this->environmentOfPulse(), 'PULSE_QUEUE_WARNING' => '10']);
        $client = $this->clientAnswering($this->snapshot(['channels' => [['name' => 'orders_async', 'kind' => 'async', 'ready' => 50, 'delayed' => 0, 'inFlight' => 0, 'consumers' => null, 'probeError' => null, 'consumerToRun' => 'orders_async', 'handlers' => [], 'setUp' => true]]]));

        $crawler = $client->request('GET', '/services/orders');

        $section = $crawler->filter('details[data-section="hub.orders.flow"]');
        self::assertNotNull($section->attr('open'));
        self::assertSame('1 channel · 1 over its threshold', $section->filter('summary [data-summary-state]')->text());
        self::assertSame('warning', $crawler->filter('[data-tile-kind="channels"]')->attr('data-tile-status'));
    }

    public function test_the_overview_leaves_packages_consumers_and_the_channel_table_to_their_own_views(): void
    {
        $client = $this->pulseAnswering($this->snapshot());

        $crawler = $client->request('GET', '/services/orders');

        self::assertCount(0, $crawler->filter('[data-hub-setup]'));
        self::assertCount(0, $crawler->filter('[data-hub-consumers]'));
        self::assertCount(0, $crawler->filter('[data-hub-channels]'));
        self::assertCount(0, $crawler->filter('[data-feature]'));
        self::assertSame('/flow?services%5B0%5D=orders', $crawler->filter('[data-hub-graph] a')->attr('href'));
    }

    public function test_the_views_of_a_service_are_tabs_that_keep_the_service_and_mark_the_current_one(): void
    {
        $client = $this->pulseAnswering($this->snapshot());

        $overview = $client->request('GET', '/services/orders');
        $channels = $client->request('GET', '/services/orders/channels');

        $tabs = $overview->filter('nav[data-service-tabs] a');
        self::assertSame(['overview', 'channels', 'failures', 'projections', 'gathering', 'traces', 'configuration'], $tabs->each(static fn (Crawler $tab): string => (string) $tab->attr('data-service-tab')));
        self::assertSame(['/services/orders', '/services/orders/channels', '/dead-letters?service=orders', '/services/orders/projections', '/services/orders/gathering', '/traces?service=orders', '/services/orders/configuration'], $tabs->each(static fn (Crawler $tab): string => (string) $tab->attr('href')));
        self::assertSame('Failed messages 2', trim($overview->filter('[data-service-tab="failures"]')->text()));
        self::assertSame('page', $overview->filter('[data-service-tab="overview"]')->attr('aria-current'));
        self::assertSame('page', $channels->filter('[data-service-tab="channels"]')->attr('aria-current'));
        self::assertSame(['Home', 'Services', 'orders', 'Channels & consumers'], $channels->filter('nav[aria-label="Breadcrumb"] li')->each(static fn (Crawler $crumb): string => trim($crumb->text())));
    }

    public function test_the_channels_view_lists_the_service_wide_consumer_settings_once_and_only_the_overrides_under_each_consumer(): void
    {
        $client = $this->pulseAnswering($this->snapshot());

        $crawler = $client->request('GET', '/services/orders/channels');

        $shared = $crawler->filter('[data-hub-consumers] [data-consumer-shared]');
        self::assertSame(['Error channel', 'Database transaction'], $shared->filter('[data-consumer-feature]')->each(static fn (Crawler $feature): string => (string) $feature->attr('data-consumer-feature')));
        self::assertSame('dbal_dead_letter', trim($shared->filter('[data-consumer-feature="Error channel"] [data-consumer-feature-value]')->text()));
        $async = $crawler->filter('[data-hub-consumers] [data-consumer="orders_async"]');
        self::assertSame(['Delayed retries'], $async->filter('[data-consumer-feature]')->each(static fn (Crawler $feature): string => (string) $feature->attr('data-consumer-feature')));
        self::assertSame('Consumer settings · 1 override', trim($async->filter('[data-consumer-overrides] > summary')->text()));
        self::assertCount(0, $crawler->filter('[data-hub-consumers] [data-consumer="orders"] [data-consumer-feature]'));
    }

    public function test_the_channels_view_puts_the_channel_that_needs_attention_first_and_explains_a_depth_it_cannot_read(): void
    {
        $this->configurePulseWithServices('orders');
        $this->configurePulse([...$this->environmentOfPulse(), 'PULSE_QUEUE_WARNING' => '10']);
        $client = $this->clientAnswering($this->snapshot(['channels' => [
            ['name' => 'a_quiet', 'kind' => 'queue', 'ready' => 0, 'delayed' => 0, 'inFlight' => 0, 'consumers' => null, 'probeError' => null, 'consumerToRun' => 'a_quiet', 'handlers' => [], 'setUp' => true],
            ['name' => 'b_unmeasured', 'kind' => 'async', 'ready' => null, 'delayed' => null, 'inFlight' => null, 'consumers' => null, 'probeError' => null, 'consumerToRun' => 'b_unmeasured', 'handlers' => [], 'setUp' => null],
            ['name' => 'c_busy', 'kind' => 'async', 'ready' => 50, 'delayed' => 0, 'inFlight' => 0, 'consumers' => null, 'probeError' => null, 'consumerToRun' => 'c_busy', 'handlers' => [], 'setUp' => true],
        ]]));

        $crawler = $client->request('GET', '/services/orders/channels');

        self::assertSame(['c_busy', 'b_unmeasured', 'a_quiet'], $crawler->filter('[data-hub-channels] tr[data-channel]')->each(static fn (Crawler $row): string => (string) $row->attr('data-channel')));
        $unmeasured = $crawler->filter('tr[data-channel="b_unmeasured"]');
        self::assertSame('not reported', trim($unmeasured->filter('[data-ready]')->text()));
        self::assertStringContainsString('does not report', (string) $unmeasured->filter('[data-ready]')->attr('title'));
        self::assertSame('3 channels · 1 over its threshold', trim($crawler->filter('[data-hub-channels] [data-summary-state]')->text()));
    }

    public function test_the_configuration_view_shows_each_feature_in_use_on_one_line_and_collapses_the_ones_not_in_use(): void
    {
        $client = $this->pulseAnswering($this->snapshot());

        $crawler = $client->request('GET', '/services/orders/configuration');

        $core = $crawler->filter('[data-hub-setup] [data-package="core"]');
        $retries = $core->filter('[data-feature="Instant retries"]');
        self::assertSame('true', $retries->attr('data-feature-enabled'));
        self::assertNull($retries->filter('details')->attr('open'));
        self::assertSame('On Instant retries · Retry times: 3', preg_replace('/\s+/', ' ', trim($retries->filter('summary')->text())));
        $off = $core->filter('details[data-features-off]');
        self::assertNull($off->attr('open'));
        self::assertSame('Not in use · 2: Orchestrators, Scheduled endpoints', trim($off->filter('summary')->text()));
        self::assertSame('false', $off->filter('[data-feature="Orchestrators"]')->attr('data-feature-enabled'));
        self::assertSame('2 features on · 2 not in use · 1 table and 2 channels set up', $crawler->filter('[data-verdict-sentence]')->text());
    }

    public function test_the_configuration_view_collapses_the_setup_when_everything_is_set_up(): void
    {
        $client = $this->pulseAnswering($this->snapshot());

        $crawler = $client->request('GET', '/services/orders/configuration');

        $setup = $crawler->filter('details[data-section="config.orders.setup"]');
        self::assertNull($setup->attr('open'));
        self::assertSame('3 · all set up', $setup->filter('summary [data-summary-state]')->text());
        self::assertCount(1, $setup->filter('[data-setup-item="table:dead_letter"]'));
    }

    public function test_the_views_of_a_service_pulse_does_not_know_are_not_found(): void
    {
        $client = $this->pulseAnswering($this->snapshot());

        $client->request('GET', '/services/unknown/channels');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/services/unknown/configuration');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function snapshot(array $overrides = []): array
    {
        return [
            'schemaVersion' => 1, 'serviceName' => 'orders', 'takenAt' => '2026-10-20T12:00:00+00:00',
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []], 'topology' => ['serviceMaps' => []],
            'channels' => [
                ['name' => 'orders', 'kind' => 'queue', 'ready' => 0, 'delayed' => 0, 'inFlight' => 0, 'consumers' => null, 'probeError' => null, 'consumerToRun' => 'orders', 'handlers' => [], 'setUp' => true],
                ['name' => 'orders_async', 'kind' => 'async', 'ready' => null, 'delayed' => null, 'inFlight' => null, 'consumers' => null, 'probeError' => null, 'consumerToRun' => 'orders_async', 'handlers' => ['ChargeCard → charge', 'OrderPaid → confirm'], 'setUp' => true],
            ],
            'consumers' => [
                ['name' => 'orders', 'kind' => 'polling', 'handlers' => [], 'features' => [
                    ['name' => 'Error channel', 'value' => 'dbal_dead_letter', 'scope' => 'global', 'handler' => null],
                    ['name' => 'Database transaction', 'value' => 'on', 'scope' => 'global', 'handler' => null],
                ]],
                ['name' => 'orders_async', 'kind' => 'polling', 'handlers' => ['charge_card', 'confirm'], 'features' => [
                    ['name' => 'Error channel', 'value' => 'dbal_dead_letter', 'scope' => 'global', 'handler' => null],
                    ['name' => 'Database transaction', 'value' => 'on', 'scope' => 'global', 'handler' => null],
                    ['name' => 'Delayed retries', 'value' => '2 retries: after 500 ms and 1000 ms', 'scope' => 'consumer-level config', 'handler' => 'charge_card'],
                ]],
            ],
            'packages' => [['name' => 'core', 'composerPackage' => 'ecotone/ecotone', 'version' => '2.0.0', 'features' => [
                ['name' => 'Instant retries', 'enabled' => true, 'settings' => ['Retry times' => '3'], 'handlers' => [], 'hint' => 'Tried again right away.', 'configuration' => 'InstantRetryConfiguration::createWithDefaults()'],
                ['name' => 'Orchestrators', 'enabled' => false, 'settings' => [], 'handlers' => [], 'hint' => 'No method is an #[Orchestrator].', 'configuration' => '#[Orchestrator]'],
                ['name' => 'Scheduled endpoints', 'enabled' => false, 'settings' => [], 'handlers' => [], 'hint' => 'No method is marked #[Scheduled].', 'configuration' => '#[Scheduled]'],
            ]], ['name' => 'dbal', 'composerPackage' => 'ecotone/dbal', 'version' => '2.0.0', 'features' => [
                ['name' => 'Dead letter', 'enabled' => true, 'settings' => ['Storage' => 'database'], 'handlers' => [], 'hint' => 'Stored instead of lost.', 'configuration' => 'DbalConfiguration::createWithDefaults()->withDeadLetter(true)'],
            ]]],
            'setup' => ['tables' => [['feature' => 'dead_letter', 'connection' => 'default', 'initialized' => true, 'error' => null, 'package' => 'dbal']]],
            'deadLetter' => ['enabled' => true],
            'deadLetters' => ['total' => 2, 'groups' => [['failureId' => 'f-charge', 'messageName' => 'App\Payment\ChargeCard', 'exceptionClass' => 'App\Payment\PaymentProviderUnavailable', 'exceptionFile' => '/srv/app/Payment/Checkout.php', 'exceptionLine' => 35, 'exceptionMessage' => 'provider timed out', 'count' => 2, 'firstFailedAt' => '2026-10-20T11:58:00+00:00', 'lastFailedAt' => '2026-10-20T11:59:00+00:00']], 'recent' => []],
            'projections' => [],
            'tracing' => ['statsWindow' => 100, 'flowTypes' => [
                ['flowType' => 'customer.detail', 'runs' => 700, 'windowRuns' => 100, 'runsWithErrors' => 0, 'p50Ms' => 2, 'p95Ms' => 4, 'maxMs' => 9, 'lastStartedAt' => '2026-10-20T11:59:00+00:00'],
                ['flowType' => 'order.place', 'runs' => 3, 'windowRuns' => 3, 'runsWithErrors' => 0, 'p50Ms' => 20, 'p95Ms' => 40, 'maxMs' => 41, 'lastStartedAt' => '2026-10-20T11:59:00+00:00'],
            ]],
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function pulseAnswering(array $snapshot): KernelBrowser
    {
        $this->configurePulseWithServices('orders');

        return $this->clientAnswering($snapshot);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function clientAnswering(array $snapshot): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $orders = $this->serviceAnsweringWith('orders', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $client->request('GET', '/services');
        $this->runService($orders, 'orders');
        $this->runPulseWorker($client->getKernel());

        return $client;
    }

    /**
     * @return array<string, string>
     */
    private function environmentOfPulse(): array
    {
        $environment = [];
        foreach (['ECOTONE_LICENCE_KEY', 'PULSE_SERVICE_NAME', 'PULSE_CHANNEL', 'PULSE_TRANSPORT_DSN', 'PULSE_SERVICES'] as $name) {
            $environment[$name] = $_SERVER[$name];
        }

        return $environment;
    }
}
