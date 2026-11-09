<?php

declare(strict_types=1);

namespace App\Tests\Projections;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\Header;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class ProjectionStatusPageTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    private object $orders;

    public function test_a_projection_that_is_not_behind_is_up_to_date(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('tickets', events: 0)];

        $crawler = $this->observe($client);

        self::assertResponseIsSuccessful();
        self::assertSame('up_to_date', $this->statusOf($crawler, 'tickets'));
        self::assertSame('0', $crawler->filter('tr[data-projection="tickets"] [data-gap]')->text());
        self::assertSame('asynchronous', $crawler->filter('[data-projection-panel="tickets"] [data-mode]')->text());
        self::assertSame('Initialized', $crawler->filter('[data-projection-panel="tickets"] [data-initialization]')->text());
    }

    public function test_a_projection_behind_by_events_is_catching_up_with_its_gap(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('tickets', events: 12), $this->projection('capped', events: 1000, atLeast: true)];

        $crawler = $this->observe($client);

        self::assertSame('catching_up', $this->statusOf($crawler, 'tickets'));
        self::assertSame('12 events behind', $crawler->filter('tr[data-projection="tickets"] [data-gap]')->text());
        self::assertSame('1000+ events behind', $crawler->filter('tr[data-projection="capped"] [data-gap]')->text());
    }

    public function test_a_partitioned_projection_shows_how_many_partitions_are_behind(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->partitionedProjection('per_ticket', total: 120, inspected: 100, behind: 7, atLeast: true)];

        $crawler = $this->observe($client);

        self::assertSame('catching_up', $this->statusOf($crawler, 'per_ticket'));
        self::assertSame('7+ of 120 partitions behind', $crawler->filter('tr[data-projection="per_ticket"] [data-gap]')->text());
        self::assertSame('yes', $crawler->filter('[data-projection-panel="per_ticket"] [data-partitioned]')->text());
    }

    public function test_a_projection_behind_with_an_unmoved_position_for_five_minutes_is_stalled(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('tickets', events: 5, digest: 'same')];
        $this->observe($client);

        $this->advanceTimeBy(299);
        $beforeLimit = $this->observe($client);
        $this->advanceTimeBy(1);
        $atLimit = $this->observe($client);

        self::assertSame('catching_up', $this->statusOf($beforeLimit, 'tickets'));
        self::assertSame('stalled', $this->statusOf($atLimit, 'tickets'));
    }

    public function test_a_moving_position_resets_the_stalled_clock_even_while_the_gap_grows(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('tickets', events: 5, digest: 'first')];
        $this->observe($client);
        $this->advanceTimeBy(200);
        $this->orders->projections = [$this->projection('tickets', events: 9, digest: 'second')];
        $this->observe($client);
        $this->advanceTimeBy(200);

        $crawler = $this->observe($client);

        self::assertSame('catching_up', $this->statusOf($crawler, 'tickets'));
    }

    public function test_a_projection_that_is_not_behind_is_never_stalled(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('tickets', events: 0, digest: 'same')];
        $this->observe($client);
        $this->advanceTimeBy(3600);

        $crawler = $this->observe($client);

        self::assertSame('up_to_date', $this->statusOf($crawler, 'tickets'));
    }

    public function test_a_projection_whose_async_channel_has_dead_letters_is_failed(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('tickets', events: 0, asyncChannel: 'tickets_channel'), $this->projection('invoices', events: 0, asyncChannel: 'invoices_channel')];
        $this->orders->deadLetterChannels = ['tickets_channel'];

        $crawler = $this->observe($client);

        self::assertSame('failed', $this->statusOf($crawler, 'tickets'));
        self::assertSame('up_to_date', $this->statusOf($crawler, 'invoices'));
    }

    public function test_a_projection_the_service_could_not_read_says_why(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [[...$this->projection('tickets', events: 0), 'initialization' => null, 'eventsBehind' => null, 'unavailable' => 'No active tenant']];

        $crawler = $this->observe($client);

        self::assertSame('unknown', $this->statusOf($crawler, 'tickets'));
        self::assertStringContainsString('No active tenant', $crawler->filter('tr[data-projection="tickets"]')->text());
    }

    public function test_the_page_shows_when_pulse_last_saw_the_position_change(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('tickets', events: 5, digest: 'same')];
        $this->observe($client);
        $this->advanceTimeBy(120);

        $crawler = $this->observe($client);

        self::assertSame('2m ago', $crawler->filter('tr[data-projection="tickets"] [data-last-change]')->text());
    }

    public function test_a_service_without_projections_says_how_to_get_them_reported(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [];

        $crawler = $this->observe($client);

        self::assertSame(0, $crawler->filter('tr[data-projection]')->count());
        self::assertStringContainsString('#[Projection', $crawler->filter('[data-no-projections]')->text());
    }

    public function test_the_service_hub_counts_projections_behind_stalled_and_failed(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [
            $this->projection('behind', events: 3, digest: 'a'),
            $this->projection('stuck', events: 3, digest: 'b'),
            $this->projection('broken', events: 0, asyncChannel: 'broken_channel'),
            $this->projection('fine', events: 0),
        ];
        $this->orders->deadLetterChannels = ['broken_channel'];
        $this->observe($client);
        $this->advanceTimeBy(300);
        $this->orders->projections[0] = $this->projection('behind', events: 3, digest: 'moved');
        $this->observe($client);

        $crawler = $this->freshView($client, '/services/orders');

        $hub = $crawler->filter('[data-hub-projections]');
        self::assertSame('4', $hub->filter('a.tile-link b')->text());
        self::assertSame('2 behind · 1 stalled · 1 failed', $hub->filter('[data-tile-state]')->text());
        self::assertSame('critical', $hub->filter('[data-tile-kind]')->attr('data-tile-status'));
        self::assertSame('/services/orders/projections', $hub->filter('a')->attr('href'));
    }

    public function test_the_projections_page_is_the_projections_tab_of_its_service(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('tickets', events: 0)];

        $crawler = $this->observe($client);

        self::assertSame('page', $crawler->filter('[data-service-tab="projections"]')->attr('aria-current'));
        self::assertSame(['Home', 'Services', 'orders', 'Projections'], $crawler->filter('[data-crumbs] li')->each(static fn (Crawler $crumb): string => trim($crumb->text())));
    }

    public function test_up_to_date_projections_fold_behind_an_all_clear_verdict(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('invoices', events: 0), $this->projection('tickets', events: 0)];

        $crawler = $this->observe($client);

        self::assertSame('ok', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('All clear: 2 projections, all up to date.', $crawler->filter('[data-verdict-sentence]')->text());
        $quiet = $crawler->filter('[data-projections-quiet]');
        self::assertNull($quiet->attr('open'));
        self::assertSame('2 projections · all up to date', $quiet->filter('[data-summary-state]')->text());
        self::assertSame(['invoices', 'tickets'], $quiet->filter('tr[data-projection]')->each(static fn (Crawler $row): string => (string) $row->attr('data-projection')));
        self::assertCount(0, $crawler->filter('[data-projections-attention]'));
    }

    public function test_projections_that_need_attention_are_listed_first_from_the_most_urgent(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [
            $this->projection('behind', events: 3, digest: 'a'),
            $this->projection('broken', events: 0, asyncChannel: 'broken_channel'),
            $this->projection('fine', events: 0),
            $this->projection('stuck', events: 3, digest: 'b'),
            [...$this->projection('waiting', events: 0), 'initialization' => 'uninitialized', 'manualKickOff' => true],
        ];
        $this->orders->deadLetterChannels = ['broken_channel'];
        $this->observe($client);
        $this->advanceTimeBy(300);
        $this->orders->projections[0] = $this->projection('behind', events: 3, digest: 'moved');

        $crawler = $this->observe($client);

        $attention = $crawler->filter('[data-projections-attention]');
        self::assertSame(['broken', 'stuck', 'behind', 'waiting'], $attention->filter('tr[data-projection]')->each(static fn (Crawler $row): string => (string) $row->attr('data-projection')));
        self::assertSame('4 · 1 failed · 1 stalled · 1 catching up · 1 not initialized', $attention->filter('[data-summary-state]')->text());
        self::assertSame(['fine'], $crawler->filter('[data-projections-quiet] tr[data-projection]')->each(static fn (Crawler $row): string => (string) $row->attr('data-projection')));
        self::assertSame('critical', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('4 of 5 projections need attention: 1 failed, 1 stalled, 1 catching up, 1 not initialized.', $crawler->filter('[data-verdict-sentence]')->text());
        self::assertSame('#projection-orders-broken', $crawler->filter('[data-verdict-action]')->attr('href'));
    }

    public function test_the_verdict_names_the_one_projection_that_needs_attention_and_why(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('invoices', events: 0, asyncChannel: 'invoices_channel'), $this->projection('tickets', events: 0, asyncChannel: 'tickets_channel')];
        $this->orders->deadLetterChannels = ['tickets_channel'];

        $crawler = $this->observe($client);

        self::assertSame('critical', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('tickets failed: a message on its channel tickets_channel failed after its retries and is stored in dead letters.', $crawler->filter('[data-verdict-sentence]')->text());
        self::assertSame('Open tickets', $crawler->filter('[data-verdict-action]')->text());
        self::assertSame('#projection-orders-tickets', $crawler->filter('[data-verdict-action]')->attr('href'));
        self::assertSame('/dead-letters?service=orders', $crawler->filter('[data-projection-panel="tickets"] a[data-projection-failures]')->attr('href'));
    }

    public function test_a_partitioned_projection_with_nothing_left_to_project_is_up_to_date_even_when_some_partitions_are_not_initialized(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [[...$this->partitionedProjection('ledger', total: 2, inspected: 2, behind: 0, atLeast: false), 'initialization' => 'uninitialized']];

        $crawler = $this->observe($client);

        self::assertSame('up_to_date', $this->statusOf($crawler, 'ledger'));
        self::assertStringContainsString('on its first event', $crawler->filter('tr[data-projection="ledger"] [data-projection-reason]')->text());
        $initialization = $crawler->filter('[data-projection-panel="ledger"] [data-initialization]')->text();
        self::assertStringNotContainsString('Not initialized', $initialization);
        self::assertStringContainsString('on its first event', $initialization);
        self::assertSame('ok', $crawler->filter('[data-verdict]')->attr('data-verdict'));
    }

    public function test_each_projection_has_a_linked_panel_with_its_facts(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->partitionedProjection('per_ticket', total: 120, inspected: 100, behind: 7, atLeast: true)];

        $crawler = $this->observe($client);

        self::assertSame('#projection-orders-per_ticket', $crawler->filter('tr[data-projection="per_ticket"] a[data-projection-open]')->attr('href'));
        $panel = $crawler->filter('#projection-orders-per_ticket');
        self::assertSame('per_ticket', $panel->attr('data-projection-panel'));
        self::assertSame('asynchronous', $panel->filter('[data-mode]')->text());
        self::assertSame('yes', $panel->filter('[data-partitioned]')->text());
        self::assertSame('120 partitions, the first 100 inspected, 7 of them behind', $panel->filter('[data-partitions]')->text());
        self::assertSame('tickets_channel', $panel->filter('[data-async-channel]')->text());
    }

    public function test_concept_hints_explain_the_projection_states_once_and_no_disclosure_is_nested(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('tickets', events: 3), $this->projection('invoices', events: 0)];

        $crawler = $this->observe($client);

        self::assertSame(['projection', 'behind', 'stalled', 'projection-failed', 'partition'], $crawler->filter('[data-concept-legend] [data-concept]')->each(static fn (Crawler $concept): string => (string) $concept->attr('data-concept')));
        self::assertCount(0, $crawler->filter('details details'));
        self::assertStringNotContainsString('A projection is stalled when', $crawler->filter('body')->text());
    }

    public function test_the_projections_tab_of_a_tenant_can_be_chosen_with_the_tenant_parameter(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('tickets', events: 0)];
        $this->observe($client);

        $client->request('GET', '/services/orders/projections?tenant=tenant_x');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_unknown_service_has_no_projections_page(): void
    {
        $client = $this->pulseWithOrders();

        $client->request('GET', '/services/unknown/projections');

        self::assertResponseStatusCodeSame(404);
    }

    private function pulseWithOrders(): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->orders = new class () {
            public array $projections = [];
            public array $deadLetterChannels = [];

            #[Distributed]
            #[CommandHandler('ecotone.pulse.trackings.list', endpointId: 'orders.trackings')]
            public function trackings(string $request, #[Header('ecotone_pulse_query_id')] string $queryId): string
            {
                return json_encode(['queryId' => $queryId, 'cap' => 100, 'truncated' => false, 'trackings' => []], JSON_THROW_ON_ERROR);
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.snapshot')]
            public function snapshot(string $request): string
            {
                return json_encode([
                    'schemaVersion' => 1,
                    'serviceName' => 'orders',
                    'takenAt' => gmdate('c'),
                    'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => PHP_VERSION, 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
                    'flowGraph' => ['nodes' => [], 'edges' => []],
                    'topology' => ['serviceMaps' => []],
                    'channels' => [],
                    'deadLetters' => [
                        'total' => count($this->deadLetterChannels),
                        'groups' => [],
                        'recent' => array_map(static fn (string $channel): array => ['messageId' => 'm-' . $channel, 'messageName' => 'x', 'failedAt' => gmdate('c'), 'exceptionClass' => 'RuntimeException', 'exceptionMessage' => 'failed', 'stackTrace' => '#0', 'payload' => '{}', 'contentType' => 'application/json', 'channel' => $channel], $this->deadLetterChannels),
                    ],
                    'projections' => $this->projections,
                ], JSON_THROW_ON_ERROR);
            }
        };
        $this->services['orders'] = $this->serviceApplication('orders', [$this->orders::class], [$this->orders], false);

        return $client;
    }

    private array $services = [];

    private function observe(KernelBrowser $client): Crawler
    {
        $client->request('POST', '/refresh');
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', '/services/orders/projections');
    }

    private function freshView(KernelBrowser $client, string $uri): Crawler
    {
        $client->request('POST', '/refresh');
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', $uri);
    }

    private function statusOf(Crawler $crawler, string $projection): string
    {
        return $crawler->filter(sprintf('tr[data-projection="%s"] [data-projection-status]', $projection))->attr('data-projection-status');
    }

    private function projection(string $name, int $events, bool $atLeast = false, string $digest = 'digest', ?string $asyncChannel = 'tickets_channel'): array
    {
        return [
            'name' => $name,
            'mode' => $asyncChannel === null ? 'synchronous' : 'asynchronous',
            'partitioned' => false,
            'asyncChannel' => $asyncChannel,
            'initialization' => 'initialized',
            'positionDigest' => $digest,
            'eventsBehind' => ['events' => $events, 'atLeast' => $atLeast],
            'partitions' => null,
            'unavailable' => null,
        ];
    }

    private function partitionedProjection(string $name, int $total, int $inspected, int $behind, bool $atLeast): array
    {
        return [...$this->projection($name, events: 0), 'partitioned' => true, 'eventsBehind' => null, 'partitions' => ['total' => $total, 'inspected' => $inspected, 'behind' => $behind, 'atLeast' => $atLeast]];
    }
}
