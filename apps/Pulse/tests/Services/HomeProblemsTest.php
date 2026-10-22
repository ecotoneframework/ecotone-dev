<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\ProblemBoardServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;

/**
 * @internal
 */
final class HomeProblemsTest extends ReleasingWebTestCase
{
    use ProblemBoardServicesTrait;

    public function test_a_service_that_never_answers_is_a_critical_problem_linking_to_its_page(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        $client->request('GET', '/');
        $this->advanceTimeBy(10);
        $crawler = $client->request('GET', '/');

        $problems = $this->problemsOf($crawler, 'orders');
        self::assertSame(['critical'], array_column($problems, 'severity'));
        self::assertSame(['unreachable'], array_column($problems, 'kind'));
        self::assertSame('/services/orders', $problems[0]['href']);
    }

    public function test_a_channel_over_the_critical_threshold_says_how_many_are_ready_and_links_to_the_channels(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [$this->channel('invoices', 12400)];

        $crawler = $this->observeBoard($client);

        self::assertSame([['text' => 'invoices: 12 400 ready (critical)', 'href' => '/services/orders#channels', 'severity' => 'critical', 'kind' => 'queue-critical']], $this->problemsOf($crawler, 'orders'));
    }

    public function test_a_channel_over_the_warning_threshold_is_a_warning(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [$this->channel('invoices', 1200), $this->channel('emails', 3)];

        $crawler = $this->observeBoard($client);

        self::assertSame([['text' => 'invoices: 1 200 ready (warning)', 'href' => '/services/orders#channels', 'severity' => 'warning', 'kind' => 'queue-warning']], $this->problemsOf($crawler, 'orders'));
    }

    public function test_a_failed_projection_is_critical_and_links_to_the_projections_of_the_service(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->projections = [$this->projectionReport('orders_projection', eventsBehind: 4, asyncChannel: 'projection_channel')];
        $this->boardState['orders']->deadLetters = ['total' => 1, 'groups' => [$this->failureGroup('OrderPlaced', 1)], 'recent' => [['messageId' => 'm1', 'channel' => 'projection_channel', 'failureId' => 'failure-1']]];

        $crawler = $this->observeBoard($client);

        $failed = array_values(array_filter($this->problemsOf($crawler, 'orders'), static fn (array $problem): bool => $problem['kind'] === 'projection-failed'));
        self::assertSame([['text' => 'orders_projection failed: its channel has dead letters', 'href' => '/services/orders/projections', 'severity' => 'critical', 'kind' => 'projection-failed']], $failed);
    }

    public function test_a_projection_that_stopped_moving_is_a_warning_that_says_for_how_long(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->projections = [$this->projectionReport('orders_projection', eventsBehind: 4)];
        $this->settleBoard($client);

        $this->advanceTimeBy(7 * 60);
        $crawler = $this->observeBoard($client);

        self::assertSame([['text' => 'orders_projection stalled 7 min', 'href' => '/services/orders/projections', 'severity' => 'warning', 'kind' => 'projection-stalled']], $this->problemsOf($crawler, 'orders'));
    }

    public function test_a_projection_catching_up_is_a_warning(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->projections = [$this->projectionReport('orders_projection', eventsBehind: 12)];

        $crawler = $this->observeBoard($client);

        self::assertSame([['text' => 'orders_projection behind: 12 events behind', 'href' => '/services/orders/projections', 'severity' => 'warning', 'kind' => 'projection-behind']], $this->problemsOf($crawler, 'orders'));
    }

    public function test_dead_letter_failures_are_a_warning_per_failure_linking_to_that_failure(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->deadLetters = ['total' => 4, 'groups' => [$this->failureGroup('OrderPlaced', 3, 'f-placed'), $this->failureGroup('OrderCancelled', 1, 'f-cancelled')], 'recent' => []];

        $crawler = $this->observeBoard($client);

        self::assertSame([
            ['text' => '3 failures in OrderPlaced', 'href' => '/dead-letters?service=orders&failureId=f-placed', 'severity' => 'warning', 'kind' => 'dead-letters'],
            ['text' => '1 failure in OrderCancelled', 'href' => '/dead-letters?service=orders&failureId=f-cancelled', 'severity' => 'warning', 'kind' => 'dead-letters'],
        ], $this->problemsOf($crawler, 'orders'));
    }

    public function test_dead_letters_that_grew_since_the_previous_snapshot_are_critical(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->deadLetters = ['total' => 2, 'groups' => [$this->failureGroup('OrderPlaced', 2)], 'recent' => []];
        $this->refreshBoardOnce($client);

        $this->boardState['orders']->deadLetters = ['total' => 5, 'groups' => [$this->failureGroup('OrderPlaced', 5)], 'recent' => []];
        $this->refreshBoardOnce($client);
        $crawler = $client->request('GET', '/');

        $growing = array_values(array_filter($this->problemsOf($crawler, 'orders'), static fn (array $problem): bool => $problem['kind'] === 'dead-letters-growing'));
        self::assertSame([['text' => 'Dead letters growing: 3 new since the last snapshot', 'href' => '/dead-letters?service=orders', 'severity' => 'critical', 'kind' => 'dead-letters-growing']], $growing);
    }

    public function test_dead_letters_that_did_not_grow_are_not_critical(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->deadLetters = ['total' => 2, 'groups' => [$this->failureGroup('OrderPlaced', 2)], 'recent' => []];
        $this->settleBoard($client);

        $crawler = $this->observeBoard($client);

        self::assertSame(['warning'], array_column($this->problemsOf($crawler, 'orders'), 'severity'));
    }

    public function test_a_service_on_an_older_ecotone_version_than_another_is_a_warning(): void
    {
        $client = $this->boardWith(['orders' => null, 'billing' => null]);
        $this->boardState['billing']->version = '2.1.0';

        $crawler = $this->observeBoard($client);

        self::assertSame([['text' => 'Runs Ecotone 2.0.0 while another service runs 2.1.0', 'href' => '/services', 'severity' => 'warning', 'kind' => 'version-drift']], $this->problemsOf($crawler, 'orders'));
        self::assertSame([], $this->problemsOf($crawler, 'billing'));
    }

    public function test_a_running_rebuild_is_info_and_a_failed_one_is_a_warning(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->projections = [$this->projectionReport('totals'), $this->projectionReport('broken')];
        $this->boardState['orders']->trackings = [$this->tracking('totals', 'running', 65000), $this->tracking('broken', 'failed')];

        $crawler = $this->observeBoard($client);

        self::assertSame([
            ['text' => 'broken rebuild failed in the last 7 days', 'href' => '/services/orders/projections', 'severity' => 'warning', 'kind' => 'rebuild-failed'],
            ['text' => 'totals rebuilding for 1m 5s', 'href' => '/services/orders/projections', 'severity' => 'info', 'kind' => 'rebuild-running'],
        ], $this->problemsOf($crawler, 'orders'));
    }

    public function test_a_projection_that_is_not_initialized_is_not_a_problem_of_the_home_board(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->projections = [$this->projectionReport('waiting', initialization: 'uninitialized'), $this->projectionReport('ready')];

        $crawler = $this->observeBoard($client);

        self::assertSame([], $this->problemsOf($crawler, 'orders'));
    }

    public function test_a_running_backfill_is_info_and_a_failed_one_is_a_warning(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->projections = [$this->projectionReport('totals'), $this->projectionReport('broken')];
        $this->boardState['orders']->trackings = [$this->tracking('totals', 'running', 65000, 'projection-backfill'), $this->tracking('broken', 'failed', null, 'projection-backfill')];

        $crawler = $this->observeBoard($client);

        self::assertSame([
            ['text' => 'broken backfill failed in the last 7 days', 'href' => '/services/orders/projections', 'severity' => 'warning', 'kind' => 'backfill-failed'],
            ['text' => 'totals backfilling for 1m 5s', 'href' => '/services/orders/projections', 'severity' => 'info', 'kind' => 'backfill-running'],
        ], $this->problemsOf($crawler, 'orders'));
    }

    public function test_a_rebuild_that_finished_is_not_a_problem(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->projections = [$this->projectionReport('totals')];
        $this->boardState['orders']->trackings = [$this->tracking('totals', 'done')];

        $crawler = $this->observeBoard($client);

        self::assertSame([], $this->problemsOf($crawler, 'orders'));
    }

    public function test_a_tenant_scoped_problem_links_to_the_page_of_that_tenant(): void
    {
        $client = $this->boardWith(['orders' => ['tenant_a', 'tenant_b']]);
        $this->boardState['orders']->tenantSections = [
            'tenant_a' => ['channels' => [$this->channel('invoices', 12400)], 'deadLetters' => ['total' => 3, 'groups' => [$this->failureGroup('OrderPlaced', 3, 'f-placed')], 'recent' => []], 'projections' => [$this->projectionReport('totals', eventsBehind: 2)]],
            'tenant_b' => ['channels' => [], 'deadLetters' => ['total' => 0, 'groups' => [], 'recent' => []], 'projections' => []],
        ];

        $crawler = $this->observeBoard($client);

        self::assertSame([
            ['text' => 'invoices: 12 400 ready (critical)', 'href' => '/services/orders?tenant=tenant_a#channels', 'severity' => 'critical', 'kind' => 'queue-critical'],
            ['text' => 'totals behind: 2 events behind', 'href' => '/services/orders@tenant_a/projections', 'severity' => 'warning', 'kind' => 'projection-behind'],
            ['text' => '3 failures in OrderPlaced', 'href' => '/dead-letters?service=orders@tenant_a&failureId=f-placed', 'severity' => 'warning', 'kind' => 'dead-letters'],
        ], $this->problemsOf($crawler, 'orders@tenant_a'));
        self::assertSame([], $this->problemsOf($crawler, 'orders@tenant_b'));
    }
}
