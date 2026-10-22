<?php

declare(strict_types=1);

namespace App\Tests\Projections;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use DateTimeImmutable;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Messaging\MessageHeaders;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class ProjectionRebuildPageTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    private const TRACE = '0af7651916cd43dd8448eb211c80319c';
    private const OTHER_TRACE = '1af7651916cd43dd8448eb211c80319c';

    private object $orders;

    public function test_every_projection_offers_a_rebuild_whose_confirmation_says_how_it_runs(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true, rebuildChannel: 'rebuild_channel'), $this->projection('totals', partitioned: false, rebuildChannel: 'rebuild_channel'), $this->projection('inline', partitioned: false, rebuildChannel: null)];

        $crawler = $this->observe($client);

        self::assertStringContainsString('batches', $crawler->filter('[data-rebuild-confirm="per_ticket"] [data-rebuild-explanation]')->text());
        self::assertStringContainsString('percentage', $crawler->filter('[data-rebuild-confirm="per_ticket"] [data-rebuild-explanation]')->text());
        self::assertStringContainsString('one transaction', $crawler->filter('[data-rebuild-confirm="totals"] [data-rebuild-explanation]')->text());
        self::assertStringNotContainsString('inside the request', $crawler->filter('[data-rebuild-confirm="totals"] [data-rebuild-explanation]')->text());
        self::assertStringContainsString('inside the request', $crawler->filter('[data-rebuild-confirm="inline"] [data-rebuild-explanation]')->text());
        self::assertStringContainsString('may arrive late', $crawler->filter('[data-rebuild-confirm="inline"] [data-rebuild-explanation]')->text());
        self::assertSame('/services/orders/projections/totals/rebuild', $crawler->filter('[data-rebuild-confirm="totals"] form')->attr('action'));
    }

    public function test_a_projection_that_is_not_initialized_says_so_and_offers_to_initialize_it(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('waiting', initialization: 'uninitialized'), $this->projection('ready'), $this->projection('per_ticket', partitioned: true, initialization: 'uninitialized'), $this->projection('feed', mode: 'streaming', initialization: null)];

        $crawler = $this->observe($client);

        self::assertSame('not_initialized', $this->statusOf($crawler, 'waiting'));
        self::assertSame('Not initialized', trim($crawler->filter('tr[data-projection="waiting"] [data-projection-status]')->text()));
        self::assertSame('/services/orders/projections/waiting/initialize', $crawler->filter('[data-initialize-confirm="waiting"] form')->attr('action'));
        self::assertStringContainsString('kick', $crawler->filter('[data-initialize-confirm="waiting"] [data-initialize-explanation]')->text());
        self::assertCount(0, $crawler->filter('[data-initialize-confirm="ready"]'));
        self::assertCount(0, $crawler->filter('[data-initialize-confirm="per_ticket"]'));
        self::assertNotSame('not_initialized', $this->statusOf($crawler, 'per_ticket'));
        self::assertCount(0, $crawler->filter('[data-initialize-confirm="feed"]'));
    }

    public function test_every_projection_that_keeps_a_position_offers_a_backfill_whose_confirmation_says_how_it_runs(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals', backfillChannel: 'backfill_channel'), $this->projection('inline', backfillChannel: null), $this->projection('feed', mode: 'streaming', initialization: null)];

        $crawler = $this->observe($client);

        self::assertStringContainsString('"backfill_channel"', $crawler->filter('[data-backfill-confirm="totals"] [data-backfill-explanation]')->text());
        self::assertStringContainsString('inside the request', $crawler->filter('[data-backfill-confirm="inline"] [data-backfill-explanation]')->text());
        self::assertSame('/services/orders/projections/totals/backfill', $crawler->filter('[data-backfill-confirm="totals"] form')->attr('action'));
        self::assertCount(0, $crawler->filter('[data-backfill-confirm="feed"]'));
    }

    public function test_a_confirmed_initialization_and_a_confirmed_backfill_are_sent_to_the_service_under_new_tracing_ids(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('waiting', initialization: 'uninitialized')];
        $this->observe($client);

        $client->request('POST', '/services/orders/projections/waiting/initialize', ['confirm' => '1']);
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());
        $afterInitialize = $client->followRedirect();
        $client->request('POST', '/services/orders/projections/waiting/backfill', ['confirm' => '1']);
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());
        $afterBackfill = $client->followRedirect();

        self::assertSame(['initialize', 'backfill'], array_column($this->orders->actionRequests, 'action'));
        self::assertSame(['waiting', 'waiting'], array_column($this->orders->actionRequests, 'projection'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->orders->actionRequests[0]['tracingId']);
        self::assertNotSame($this->orders->actionRequests[0]['tracingId'], $this->orders->actionRequests[1]['tracingId']);
        self::assertStringContainsString('Initialization of waiting requested', $afterInitialize->filter('[data-notice]')->text());
        self::assertStringContainsString('Backfill of waiting requested', $afterBackfill->filter('[data-notice]')->text());
    }

    public function test_an_initialization_and_a_backfill_that_were_not_confirmed_or_name_an_unknown_projection_send_nothing(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('waiting', initialization: 'uninitialized')];
        $this->observe($client);

        $client->request('POST', '/services/orders/projections/waiting/initialize');
        $notConfirmed = $client->followRedirect();
        $client->request('POST', '/services/orders/projections/missing/backfill', ['confirm' => '1']);
        $unknown = $client->followRedirect();
        $this->runService($this->services['orders'], 'orders');

        self::assertSame([], $this->orders->actionRequests);
        self::assertStringContainsString('initialization of "waiting" was not confirmed', $notConfirmed->filter('[data-notice-error]')->text());
        self::assertStringContainsString('does not report a projection "missing"', $unknown->filter('[data-notice-error]')->text());
    }

    public function test_recent_operations_name_the_kind_of_each_and_a_running_initialization_or_backfill_shows_in_the_projection_row(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('waiting'), $this->projection('totals')];
        $this->orders->trackings = [
            $this->tracking('waiting', 'done', durationMs: 1500, type: 'projection-initialization'),
            $this->tracking('totals', 'running', elapsedMs: 3000, type: 'projection-backfill', tracingId: self::OTHER_TRACE),
        ];

        $crawler = $this->observe($client);

        $rows = $crawler->filter('[data-recent-rebuilds] tr[data-rebuild]');
        self::assertSame(['Initialization', 'Backfill'], $rows->each(fn (Crawler $row): string => trim($row->filter('[data-operation-type]')->text())));
        self::assertSame('Initialized', trim($crawler->filter('tr[data-projection="waiting"] [data-rebuild-state] .pill')->text()));
        self::assertSame('running', $crawler->filter('tr[data-projection="totals"] [data-rebuild-state]')->attr('data-rebuild-state'));
        self::assertSame('backfilling', $this->statusOf($crawler, 'totals'));
    }

    public function test_an_initialization_whose_duration_the_service_could_not_tell_shows_a_dash_instead_of_a_duration(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('waiting'), $this->projection('totals')];
        $this->orders->trackings = [
            $this->tracking('waiting', 'done', durationMs: null, type: 'projection-initialization'),
            $this->tracking('totals', 'done', durationMs: 5000, type: 'projection-initialization', tracingId: self::OTHER_TRACE),
        ];

        $crawler = $this->observe($client);

        $cells = $crawler->filter('[data-recent-rebuilds] tr[data-rebuild]')->each(fn (Crawler $row): string => trim($row->filter('td')->eq(3)->text()));
        self::assertSame(['–', '5s'], $cells);
    }

    public function test_a_confirmed_rebuild_is_sent_to_the_service_under_a_new_tracing_id(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->observe($client);

        $client->request('POST', '/services/orders/projections/totals/rebuild', ['confirm' => '1']);
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());
        $crawler = $client->followRedirect();

        self::assertCount(1, $this->orders->rebuildRequests);
        self::assertSame('totals', $this->orders->rebuildRequests[0]['projection']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->orders->rebuildRequests[0]['tracingId']);
        self::assertStringContainsString('Rebuild of totals requested', $crawler->filter('[data-notice]')->text());
    }

    public function test_a_rebuild_that_was_not_confirmed_sends_nothing(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->observe($client);

        $client->request('POST', '/services/orders/projections/totals/rebuild');
        $this->runService($this->services['orders'], 'orders');
        $crawler = $client->followRedirect();

        self::assertSame([], $this->orders->rebuildRequests);
        self::assertStringContainsString('not confirmed', $crawler->filter('[data-notice-error]')->text());
    }

    public function test_a_projection_the_service_does_not_report_is_not_rebuilt(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->observe($client);

        $client->request('POST', '/services/orders/projections/missing/rebuild', ['confirm' => '1']);
        $this->runService($this->services['orders'], 'orders');
        $crawler = $client->followRedirect();

        self::assertSame([], $this->orders->rebuildRequests);
        self::assertStringContainsString('does not report a projection "missing"', $crawler->filter('[data-notice-error]')->text());
    }

    public function test_a_running_partitioned_rebuild_shows_its_percentage_and_the_projection_as_rebuilding(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true, events: 0)];
        $this->orders->trackings = [$this->tracking('per_ticket', 'running', batchesDone: 2, batchCount: 5)];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="per_ticket"]');
        self::assertSame('rebuilding', $row->filter('[data-projection-status]')->attr('data-projection-status'));
        self::assertSame('running', $row->filter('[data-rebuild-state]')->attr('data-rebuild-state'));
        self::assertSame('40%', $row->filter('[data-rebuild-percent]')->text());
        self::assertSame('2 of 5 batches', $row->filter('[data-rebuild-batches]')->text());
        self::assertSame('40', $row->filter('progress')->attr('value'));
    }

    public function test_a_running_rebuild_of_a_non_partitioned_projection_shows_the_time_it_has_been_running(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->orders->trackings = [$this->tracking('totals', 'running', batchesDone: 0, batchCount: null, elapsedMs: 65000)];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="totals"]');
        self::assertSame('rebuilding', $row->filter('[data-projection-status]')->attr('data-projection-status'));
        self::assertSame('running for 1m 5s', $row->filter('[data-rebuild-elapsed]')->text());
        self::assertCount(0, $row->filter('progress'));
    }

    public function test_a_finished_rebuild_shows_done_with_its_duration_and_a_failed_one_shows_failed(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals'), $this->projection('broken')];
        $this->orders->trackings = [$this->tracking('totals', 'done', batchesDone: 1, batchCount: 1, durationMs: 4200), $this->tracking('broken', 'failed', deadLetterMessageId: 'dead-1', tracingId: self::OTHER_TRACE)];

        $crawler = $this->observe($client);

        self::assertSame('done', $crawler->filter('tr[data-projection="totals"] [data-rebuild-state]')->attr('data-rebuild-state'));
        self::assertSame('in 4.2s', $crawler->filter('tr[data-projection="totals"] [data-rebuild-duration]')->text());
        self::assertSame('up_to_date', $this->statusOf($crawler, 'totals'));
        self::assertSame('failed', $crawler->filter('tr[data-projection="broken"] [data-rebuild-state]')->attr('data-rebuild-state'));
    }

    public function test_the_recent_rebuilds_list_links_every_rebuild_to_its_trace(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->orders->trackings = [$this->tracking('totals', 'done', batchesDone: 1, batchCount: 1, durationMs: 1000, tracingId: self::TRACE), $this->tracking('totals', 'done', batchesDone: 1, batchCount: 1, durationMs: 2000, tracingId: self::OTHER_TRACE)];

        $crawler = $this->observe($client);

        $rows = $crawler->filter('[data-recent-rebuilds] tr[data-rebuild]');
        self::assertSame([self::TRACE, self::OTHER_TRACE], $rows->each(fn (Crawler $row): string => $row->attr('data-rebuild')));
        self::assertSame('/traces/trace/' . self::TRACE, $rows->first()->filter('a[data-rebuild-trace]')->attr('href'));
    }

    public function test_the_service_is_asked_for_rebuilds_started_within_the_tracking_days(): void
    {
        $client = $this->pulseWithOrders(['PULSE_TRACKING_DAYS' => '3']);
        $this->orders->projections = [$this->projection('totals')];

        $this->observe($client);

        $expected = self::getContainer()->get('pulse.clock')->now()->modify('-3 days')->getTimestamp();
        self::assertEqualsWithDelta($expected, (new DateTimeImmutable($this->orders->trackingsSince))->getTimestamp(), 60);
    }

    public function test_rebuilds_are_read_from_the_service_so_they_survive_the_loss_of_pulse_state(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->orders->trackings = [$this->tracking('totals', 'running', batchesDone: 0, batchCount: null, elapsedMs: 1000)];
        $this->observe($client);

        $this->resetPulseState($client);
        $crawler = $this->observe($client);

        self::assertSame('rebuilding', $this->statusOf($crawler, 'totals'));
        self::assertSame('running', $crawler->filter('tr[data-projection="totals"] [data-rebuild-state]')->attr('data-rebuild-state'));
    }

    private function resetPulseState(KernelBrowser $client): void
    {
        $path = $_SERVER['PULSE_INTERNAL_DATABASE_PATH'];
        $client->getKernel()->shutdown();
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $client->getKernel()->boot();
        $this->setUpPulseDatabase($client->getKernel());
    }

    private function observe(KernelBrowser $client): Crawler
    {
        $client->request('POST', '/refresh');
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());
        $client->request('GET', '/services/orders/projections');
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', '/services/orders/projections');
    }

    private function statusOf(Crawler $crawler, string $projection): string
    {
        return $crawler->filter(sprintf('tr[data-projection="%s"] [data-projection-status]', $projection))->attr('data-projection-status');
    }

    /**
     * @param array<string, string> $environment
     */
    private function pulseWithOrders(array $environment = []): KernelBrowser
    {
        $suffix = uniqid();
        $this->pulseChannel = 'pulse_' . $suffix;
        $this->serviceChannels = ['orders' => 'orders_' . $suffix];
        $this->configurePulse(['PULSE_CHANNEL' => $this->pulseChannel, 'PULSE_SERVICES' => 'orders:' . $this->serviceChannels['orders'], ...$environment]);
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->orders = new class () {
            public array $projections = [];
            public array $trackings = [];
            public array $rebuildRequests = [];
            public array $actionRequests = [];
            public ?string $trackingsSince = null;

            #[Distributed]
            #[CommandHandler('ecotone.pulse.snapshot', endpointId: 'orders.snapshot')]
            public function snapshot(string $request): string
            {
                return $this->snapshotJson();
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.trackings.list', endpointId: 'orders.trackings')]
            public function trackings(string $request, #[Header('ecotone_pulse_query_id')] string $queryId): string
            {
                $this->trackingsSince = json_decode($request, true)['since'];

                return json_encode(['queryId' => $queryId, 'cap' => 100, 'truncated' => false, 'trackings' => $this->trackings], JSON_THROW_ON_ERROR);
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.projections.rebuild', endpointId: 'orders.rebuild')]
            public function rebuild(string $request, #[Header(MessageHeaders::TRACING_ID)] string $tracingId): string
            {
                $this->rebuildRequests[] = ['projection' => json_decode($request, true)['projection'], 'tracingId' => $tracingId];

                return $this->snapshotJson();
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.projections.initialize', endpointId: 'orders.initialize')]
            public function initialize(string $request, #[Header(MessageHeaders::TRACING_ID)] string $tracingId): string
            {
                $this->actionRequests[] = ['action' => 'initialize', 'projection' => json_decode($request, true)['projection'], 'tracingId' => $tracingId];

                return $this->snapshotJson();
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.projections.backfill', endpointId: 'orders.backfill')]
            public function backfill(string $request, #[Header(MessageHeaders::TRACING_ID)] string $tracingId): string
            {
                $this->actionRequests[] = ['action' => 'backfill', 'projection' => json_decode($request, true)['projection'], 'tracingId' => $tracingId];

                return $this->snapshotJson();
            }

            private function snapshotJson(): string
            {
                return json_encode([
                    'schemaVersion' => 1,
                    'serviceName' => 'orders',
                    'takenAt' => gmdate('c'),
                    'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => PHP_VERSION, 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
                    'flowGraph' => ['nodes' => [], 'edges' => []],
                    'topology' => ['serviceMaps' => []],
                    'channels' => [],
                    'deadLetters' => ['total' => 0, 'groups' => [], 'recent' => []],
                    'projections' => $this->projections,
                ], JSON_THROW_ON_ERROR);
            }
        };
        $this->services['orders'] = $this->serviceApplication('orders', [$this->orders::class], [$this->orders], false);

        return $client;
    }

    private array $services = [];

    private function projection(string $name, bool $partitioned = false, int $events = 0, ?string $rebuildChannel = 'rebuild_channel', ?string $initialization = 'initialized', string $mode = 'synchronous', ?string $backfillChannel = null): array
    {
        return [
            'name' => $name,
            'mode' => $mode,
            'partitioned' => $partitioned,
            'asyncChannel' => null,
            'rebuildAsyncChannel' => $rebuildChannel,
            'backfillAsyncChannel' => $backfillChannel,
            'initialization' => $initialization,
            'positionDigest' => 'digest',
            'eventsBehind' => $partitioned ? null : ['events' => $events, 'atLeast' => false],
            'partitions' => $partitioned ? ['total' => 5, 'inspected' => 5, 'behind' => 0, 'atLeast' => false] : null,
            'unavailable' => null,
        ];
    }

    private function tracking(string $projection, string $state, int $batchesDone = 0, ?int $batchCount = null, ?int $elapsedMs = null, ?int $durationMs = null, ?string $deadLetterMessageId = null, string $tracingId = self::TRACE, string $type = 'projection-rebuild'): array
    {
        return [
            'tracingId' => $tracingId,
            'category' => 'ecotone-pulse',
            'type' => $type,
            'reference' => $projection,
            'tenant' => null,
            'startedAt' => gmdate('Y-m-d\TH:i:s.000000+00:00'),
            'state' => $state,
            'batchesDone' => $batchesDone,
            'batchCount' => $batchCount,
            ...match ($state) {
                'done' => ['durationMs' => $durationMs],
                'failed' => ['deadLetterMessageId' => $deadLetterMessageId],
                default => ['elapsedMs' => $elapsedMs],
            },
        ];
    }
}
