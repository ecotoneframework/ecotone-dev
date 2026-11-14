<?php

declare(strict_types=1);

namespace App\Tests\Projections;

use App\Tests\Support\FailureRepliesTrait;
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
    use FailureRepliesTrait;

    private const TRACE = '0af7651916cd43dd8448eb211c80319c';
    private const OTHER_TRACE = '1af7651916cd43dd8448eb211c80319c';

    private object $orders;

    public function test_a_rebuild_the_service_refused_shows_its_message_where_the_operator_acted_instead_of_the_requested_notice(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->observe($client);

        $client->request('POST', '/services/orders/projections/totals/rebuild', ['confirm' => '1']);
        $this->serviceRefuses('orders', 'ecotone.pulse.projections.rebuild', 'Pulse asked to rebuild projection totals, but it is already rebuilding.');
        $this->runPulseWorker($client->getKernel());
        $page = $client->followRedirect();

        self::assertStringContainsString('The rebuild of "totals" was refused by orders: Pulse asked to rebuild projection totals, but it is already rebuilding.', $page->filter('[data-notice-error][data-service-refusal="orders"]')->text());
        self::assertCount(0, $page->filter('[data-notice]'));
    }

    public function test_a_backfill_the_service_refused_shows_its_own_message(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('waiting')];
        $this->observe($client);

        $client->request('POST', '/services/orders/projections/waiting/backfill', ['confirm' => '1']);
        $this->serviceRefuses('orders', 'ecotone.pulse.projections.backfill', 'backfill refused');
        $this->runPulseWorker($client->getKernel());
        $page = $client->followRedirect();

        self::assertStringContainsString('backfill refused', $page->filter('[data-notice-error]')->text());
    }

    public function test_a_refusal_is_forgotten_when_the_same_kind_of_request_is_sent_again(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->observe($client);
        $client->request('POST', '/services/orders/projections/totals/rebuild', ['confirm' => '1']);
        $this->serviceRefuses('orders', 'ecotone.pulse.projections.rebuild', 'refused once');
        $this->runPulseWorker($client->getKernel());
        $client->followRedirect();

        $client->request('POST', '/services/orders/projections/totals/rebuild', ['confirm' => '1']);
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());
        $page = $client->followRedirect();

        self::assertCount(0, $page->filter('[data-notice-error]'));
        self::assertStringContainsString('Rebuild of totals requested', $page->filter('[data-notice]')->text());
    }

    public function test_tracked_operations_the_service_refused_to_list_say_so_instead_of_showing_none(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->orders->trackingsRefusal = 'the tracking table is missing';

        $page = $this->observe($client);

        self::assertStringContainsString('the tracking table is missing', $page->filter('[data-rebuilds-refused]')->text());
        self::assertCount(0, $page->filter('[data-no-rebuilds]'));
    }

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

    public function test_a_projection_waiting_for_a_manual_kick_off_says_so_with_the_console_command_and_offers_no_operation(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('waiting', initialization: 'uninitialized', manualKickOff: true), $this->projection('ready'), $this->projection('per_ticket', partitioned: true, initialization: 'uninitialized'), $this->projection('feed', mode: 'streaming', initialization: null)];

        $crawler = $this->observe($client);

        self::assertSame('not_initialized', $this->statusOf($crawler, 'waiting'));
        self::assertSame('Not initialized', trim($crawler->filter('tr[data-projection="waiting"] [data-projection-status]')->text()));
        self::assertSame('Not initialized yet', trim($crawler->filter('[data-projection-panel="waiting"] [data-initialization]')->text()));
        self::assertSame('bin/console ecotone:projection:init waiting', trim($crawler->filter('tr[data-projection="waiting"] [data-initialize-command]')->text()));
        self::assertCount(0, $crawler->filter('[data-projection-panel="waiting"] details'));
        self::assertCount(0, $crawler->filter('[data-projection-panel="waiting"] form'));
        self::assertSame('Initialized', trim($crawler->filter('[data-projection-panel="ready"] [data-initialization]')->text()));
        self::assertCount(0, $crawler->filter('tr[data-projection="ready"] [data-initialize-command]'));
        self::assertNotSame('not_initialized', $this->statusOf($crawler, 'per_ticket'));
        self::assertCount(1, $crawler->filter('[data-rebuild-confirm="per_ticket"]'));
        self::assertCount(0, $crawler->filter('a[href$="/initialize"], form[action$="/initialize"]'));
    }

    public function test_a_projection_waiting_for_its_first_event_says_so_and_offers_no_operation(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('fresh', initialization: 'uninitialized')];

        $crawler = $this->observe($client);

        self::assertSame('waiting_for_first_event', $this->statusOf($crawler, 'fresh'));
        self::assertSame('Waiting for its first event', trim($crawler->filter('tr[data-projection="fresh"] [data-projection-status]')->text()));
        self::assertCount(0, $crawler->filter('tr[data-projection="fresh"] [data-initialize-command]'));
        self::assertCount(0, $crawler->filter('[data-projection-panel="fresh"] form'));
    }

    public function test_a_projection_kicked_off_from_the_console_and_not_run_yet_offers_backfill_and_rebuild(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('kicked', initialization: 'prepared', manualKickOff: true)];

        $crawler = $this->observe($client);

        self::assertSame('prepared', $this->statusOf($crawler, 'kicked'));
        self::assertSame('Kicked off, not run yet', trim($crawler->filter('[data-projection-panel="kicked"] [data-initialization]')->text()));
        self::assertCount(1, $crawler->filter('[data-backfill-confirm="kicked"]'));
        self::assertCount(1, $crawler->filter('[data-rebuild-confirm="kicked"]'));
    }

    public function test_hint_boxes_explain_a_projection_that_is_not_initialized_one_waiting_for_its_first_event_and_a_single_stream_operation(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('waiting', initialization: 'uninitialized', manualKickOff: true), $this->projection('fresh', initialization: 'uninitialized'), $this->projection('totals')];

        $crawler = $this->observe($client);

        self::assertSame(['not-initialized', 'waiting-for-first-event', 'single-stream-operation'], $crawler->filter('aside[role="note"][data-hint]')->each(fn (Crawler $hint): string => $hint->attr('data-hint')));
        self::assertStringContainsString('never runs it', $crawler->filter('[data-hint="not-initialized"]')->text());
        self::assertStringContainsString('commits or rolls back as a whole', $crawler->filter('[data-hint="single-stream-operation"]')->text());
        self::assertStringContainsString('Waiting or Unreachable', $crawler->filter('[data-hint="single-stream-operation"]')->text());
    }

    public function test_a_service_with_only_partitioned_projections_shows_no_single_stream_hint(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true)];

        $crawler = $this->observe($client);

        self::assertCount(0, $crawler->filter('[data-hint]'));
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

    public function test_a_confirmed_backfill_is_sent_to_the_service_under_a_new_tracing_id(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->observe($client);

        $client->request('POST', '/services/orders/projections/totals/backfill', ['confirm' => '1']);
        $this->runService($this->services['orders'], 'orders');
        $this->runPulseWorker($client->getKernel());
        $afterBackfill = $client->followRedirect();

        self::assertSame(['backfill'], array_column($this->orders->actionRequests, 'action'));
        self::assertSame(['totals'], array_column($this->orders->actionRequests, 'projection'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->orders->actionRequests[0]['tracingId']);
        self::assertStringContainsString('Backfill of totals requested', $afterBackfill->filter('[data-notice]')->text());
    }

    public function test_the_removed_initialize_route_is_not_served(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('waiting', initialization: 'uninitialized', manualKickOff: true)];
        $this->observe($client);

        $client->request('POST', '/services/orders/projections/waiting/initialize', ['confirm' => '1']);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->orders->actionRequests);
    }

    public function test_a_backfill_that_was_not_confirmed_or_name_an_unknown_projection_send_nothing(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('waiting')];
        $this->observe($client);

        $client->request('POST', '/services/orders/projections/waiting/backfill');
        $notConfirmed = $client->followRedirect();
        $client->request('POST', '/services/orders/projections/missing/backfill', ['confirm' => '1']);
        $unknown = $client->followRedirect();
        $this->runService($this->services['orders'], 'orders');

        self::assertSame([], $this->orders->actionRequests);
        self::assertStringContainsString('backfill of "waiting" was not confirmed', $notConfirmed->filter('[data-notice-error]')->text());
        self::assertStringContainsString('does not report a projection "missing"', $unknown->filter('[data-notice-error]')->text());
    }

    public function test_recent_operations_name_the_kind_of_each_ignore_a_type_that_no_longer_exists_and_a_running_backfill_shows_in_the_projection_row(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('waiting'), $this->projection('totals')];
        $this->orders->trackings = [
            $this->tracking('waiting', 'done', durationMs: 1500, type: 'projection-initialization'),
            $this->tracking('totals', 'running', elapsedMs: 3000, type: 'projection-backfill', tracingId: self::OTHER_TRACE),
        ];

        $crawler = $this->observe($client);

        $rows = $crawler->filter('[data-recent-rebuilds] tr[data-rebuild]');
        self::assertSame(['Backfill'], $rows->each(fn (Crawler $row): string => trim($row->filter('[data-operation-type]')->text())));
        self::assertCount(0, $crawler->filter('tr[data-projection="waiting"] [data-rebuild-state]'));
        self::assertSame('running', $crawler->filter('tr[data-projection="totals"] [data-rebuild-state]')->attr('data-rebuild-state'));
        self::assertSame('backfilling', $this->statusOf($crawler, 'totals'));
    }

    public function test_recent_operations_fold_with_their_count_and_open_when_the_latest_of_a_projection_failed(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals'), $this->projection('broken')];
        $this->orders->trackings = [$this->tracking('totals', 'done', batchesDone: 1, batchCount: 1, durationMs: 4200), $this->tracking('broken', 'failed', deadLetterMessageId: 'dead-1', tracingId: self::OTHER_TRACE)];

        $crawler = $this->observe($client);

        $operations = $crawler->filter('[data-projection-operations]');
        self::assertNotNull($operations->attr('open'));
        self::assertSame('2 · 1 failed', $operations->filter('[data-summary-state]')->text());
        self::assertSame('warning', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertStringContainsString('the last rebuild of broken waits for a failed batch to be replayed', $crawler->filter('[data-verdict-sentence]')->text());
    }

    public function test_recent_operations_that_all_finished_stay_folded(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->orders->trackings = [$this->tracking('totals', 'done', batchesDone: 1, batchCount: 1, durationMs: 4200)];

        $crawler = $this->observe($client);

        $operations = $crawler->filter('[data-projection-operations]');
        self::assertNull($operations->attr('open'));
        self::assertSame('1 · all done', $operations->filter('[data-summary-state]')->text());
    }

    public function test_rebuild_and_backfill_name_their_projection_with_what_they_affect_and_explain_what_they_do(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true), $this->projection('totals', events: 12)];

        $crawler = $this->observe($client);

        self::assertSame('Rebuild per_ticket · 5 partitions', trim($crawler->filter('[data-rebuild-confirm="per_ticket"] > summary')->text()));
        self::assertSame('Rebuild per_ticket · 5 partitions', trim($crawler->filter('[data-rebuild-confirm="per_ticket"] button[data-rebuild-submit]')->text()));
        self::assertSame('Backfill totals · 12 events behind', trim($crawler->filter('[data-backfill-confirm="totals"] > summary')->text()));
        self::assertStringContainsString('#[ProjectionReset]', $crawler->filter('[data-rebuild-confirm="totals"] [data-rebuild-explanation]')->text());
        self::assertStringContainsString('projected again on top of', $crawler->filter('[data-rebuild-confirm="totals"] [data-rebuild-explanation]')->text());
        self::assertStringContainsString('nothing is reset or deleted', $crawler->filter('[data-backfill-confirm="totals"] [data-backfill-explanation]')->text());
        self::assertCount(1, $crawler->filter('[data-rebuild-confirm="totals"] button.danger'));
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
        self::assertSame('2 of 5 batches recorded', $row->filter('[data-rebuild-batches]')->text());
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
        self::assertSame('2 projections are up to date, but the last rebuild of broken waits for a failed batch to be replayed.', $crawler->filter('[data-verdict-sentence]')->text());
    }

    public function test_a_rebuild_with_a_failed_batch_waits_for_its_replay_and_links_its_dead_letter(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true)];
        $this->orders->trackings = [$this->tracking('per_ticket', 'failed', batchesDone: 2, batchCount: 3, deadLetterMessageId: 'dead-2', missingBatches: [2], failedBatches: [['batch' => 2, 'deadLetterMessageId' => 'dead-2']], groupId: 'group-1')];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="per_ticket"]');
        self::assertSame('failed', $row->filter('[data-rebuild-state]')->attr('data-rebuild-state'));
        self::assertSame('Rebuild waiting', $row->filter('[data-rebuild-state-label]')->text());
        self::assertSame('2 of 3 batches', $row->filter('[data-rebuild-batches]')->text());
        self::assertSame('batch 2 failed', $row->filter('a[data-rebuild-failed-batch="2"]')->text());
        self::assertSame('/dead-letters/orders/dead-2', $row->filter('a[data-rebuild-failed-batch="2"]')->attr('href'));
        self::assertSame('replay it to continue', $row->filter('[data-rebuild-replay-hint]')->text());
        self::assertCount(0, $row->filter('[data-rebuild-missing]'));
        self::assertSame('/dead-letters/orders/dead-2', $crawler->filter('[data-recent-rebuilds] tr[data-rebuild="group-1"] a[data-rebuild-failed-batch="2"]')->attr('href'));
    }

    public function test_a_rebuild_with_a_failed_batch_and_a_batch_still_queued_names_only_the_queued_one_as_not_done(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true)];
        $this->orders->trackings = [$this->tracking('per_ticket', 'failed', batchesDone: 8, batchCount: 10, deadLetterMessageId: 'dead-2', missingBatches: [2, 3], failedBatches: [['batch' => 2, 'deadLetterMessageId' => 'dead-2']])];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="per_ticket"]');
        self::assertSame('batch 2 failed', $row->filter('a[data-rebuild-failed-batch="2"]')->text());
        self::assertSame('batch 3 not done yet', $row->filter('[data-rebuild-missing]')->text());
    }

    public function test_a_running_rebuild_whose_records_queue_behind_its_batches_shows_its_running_time_and_how_to_see_progress(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true)];
        $this->orders->trackings = [$this->tracking('per_ticket', 'running', batchesDone: 0, batchCount: 11, elapsedMs: 65000, missingBatches: range(1, 11))];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="per_ticket"]');
        self::assertCount(0, $row->filter('progress'));
        self::assertSame('running for 1m 5s', $row->filter('[data-rebuild-elapsed]')->text());
        self::assertSame('Finished batches are counted once the batches queued on rebuild_channel have run. Set batchCompletionChannelName on #[ProjectionRebuild] to count each batch as it finishes.', $row->filter('[data-rebuild-queued-records]')->text());
        self::assertSame('#[ProjectionRebuild]', $row->filter('[data-rebuild-queued-records] code')->text());
        self::assertCount(0, $row->filter('[data-rebuild-missing]'));
        self::assertCount(1, $crawler->filter('[data-rebuild-queued-records]'));
        self::assertStringNotContainsString('batchCompletionChannelName', $row->filter('[data-projection-reason]')->text());
        self::assertSame('running for 1m 5s', $crawler->filter('[data-recent-rebuilds] [data-rebuild-progress]')->text());
    }

    public function test_a_running_rebuild_whose_records_queue_behind_its_batches_counts_its_recorded_batches_after_the_first_one(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true)];
        $this->orders->trackings = [$this->tracking('per_ticket', 'running', batchesDone: 1, batchCount: 11, elapsedMs: 65000, missingBatches: range(2, 11))];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="per_ticket"]');
        self::assertSame('1 of 11 batches recorded', $row->filter('[data-rebuild-batches]')->text());
        self::assertCount(1, $row->filter('[data-rebuild-queued-records]'));
    }

    public function test_a_running_rebuild_shows_a_running_time_under_a_second_in_seconds(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->orders->trackings = [$this->tracking('totals', 'running', batchesDone: 0, batchCount: null, elapsedMs: 669)];

        $crawler = $this->observe($client);

        self::assertSame('running for under 1s', $crawler->filter('tr[data-projection="totals"] [data-rebuild-elapsed]')->text());
    }

    public function test_a_running_rebuild_with_a_separate_batch_completion_channel_shows_its_batches_from_the_first_one(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true, rebuildBatchCompletionChannel: 'batch_completion_channel')];
        $this->orders->trackings = [$this->tracking('per_ticket', 'running', batchesDone: 0, batchCount: 11, elapsedMs: 65000, missingBatches: range(1, 11))];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="per_ticket"]');
        self::assertSame('0 of 11 batches', $row->filter('[data-rebuild-batches]')->text());
        self::assertCount(0, $row->filter('[data-rebuild-queued-records]'));
    }

    public function test_a_discarded_rebuild_is_shown_as_discarded_with_the_batches_not_done(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true)];
        $this->orders->trackings = [$this->tracking('per_ticket', 'discarded', batchesDone: 2, batchCount: 3, durationMs: 1000, missingBatches: [3], groupId: 'group-1')];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="per_ticket"]');
        self::assertSame('discarded', $row->filter('[data-rebuild-state]')->attr('data-rebuild-state'));
        self::assertSame('Rebuild discarded', $row->filter('[data-rebuild-state-label]')->text());
        self::assertSame('2 of 3 batches', $row->filter('[data-rebuild-batches]')->text());
        self::assertSame('batch 3 not projected', $row->filter('[data-rebuild-missing]')->text());
        self::assertSame('discarded', $crawler->filter('[data-recent-rebuilds] tr[data-rebuild="group-1"] [data-rebuild-state-cell]')->text());
    }

    public function test_a_rebuild_released_by_hand_is_shown_as_released_with_the_batches_not_done(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true)];
        $this->orders->trackings = [$this->tracking('per_ticket', 'released', batchesDone: 2, batchCount: 3, durationMs: 1000, missingBatches: [3])];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="per_ticket"]');
        self::assertSame('Rebuild released by hand', $row->filter('[data-rebuild-state-label]')->text());
        self::assertSame('batch 3 not projected', $row->filter('[data-rebuild-missing]')->text());
        self::assertSame('released by hand', $crawler->filter('[data-recent-rebuilds] [data-rebuild-state-cell]')->text());
    }

    public function test_a_batch_whose_record_waits_in_the_dead_letter_is_shown_done_and_links_the_record(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true)];
        $this->orders->trackings = [$this->tracking('per_ticket', 'running', batchesDone: 2, batchCount: 3, elapsedMs: 1000, missingBatches: [3], batchesWaitingToBeRecorded: [['batch' => 3, 'deadLetterMessageId' => 'record-3']])];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="per_ticket"]');
        self::assertSame('running', $row->filter('[data-rebuild-state]')->attr('data-rebuild-state'));
        self::assertSame('batch 3 done, its record waits in the dead letter', $row->filter('a[data-rebuild-waiting-record="3"]')->text());
        self::assertSame('/dead-letters/orders/record-3', $row->filter('a[data-rebuild-waiting-record="3"]')->attr('href'));
        self::assertCount(0, $row->filter('[data-rebuild-missing]'));
    }

    public function test_recent_operations_show_the_state_as_a_pill_and_a_short_start_time_with_the_full_one_in_its_datetime(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true)];
        $this->orders->trackings = [[...$this->tracking('per_ticket', 'failed', batchesDone: 2, batchCount: 3, deadLetterMessageId: 'dead-2', missingBatches: [2], failedBatches: [['batch' => 2, 'deadLetterMessageId' => 'dead-2']], groupId: 'group-1'), 'startedAt' => '2026-11-14T14:47:27.998750+00:00']];

        $crawler = $this->observe($client);

        $recent = $crawler->filter('[data-recent-rebuilds] tr[data-rebuild="group-1"]');
        self::assertSame('waiting', $recent->filter('[data-rebuild-state-cell] .pill')->text());
        self::assertSame('2 of 3 batches; batch 2 failed, replay it to continue', $recent->filter('[data-rebuild-progress]')->text());
        self::assertSame('2026-11-14 14:47 UTC', $recent->filter('time[data-rebuild-started]')->text());
        self::assertSame('2026-11-14T14:47:27.998750+00:00', $recent->filter('time[data-rebuild-started]')->attr('datetime'));
    }

    public function test_a_failed_console_rebuild_of_one_batch_links_its_failed_batch(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('totals')];
        $this->orders->trackings = [$this->tracking('totals', 'failed', batchesDone: 0, batchCount: 1, deadLetterMessageId: 'dead-1', tracingId: null, startedFrom: 'console', missingBatches: [1], failedBatches: [['batch' => 1, 'deadLetterMessageId' => 'dead-1']], groupId: 'group-1')];

        $crawler = $this->observe($client);

        $recent = $crawler->filter('[data-recent-rebuilds] tr[data-rebuild="group-1"]');
        self::assertSame('/dead-letters/orders/dead-1', $recent->filter('a[data-rebuild-failed-batch="1"]')->attr('href'));
        self::assertStringNotContainsString('see its trace', $recent->text());
        self::assertSame('no trace', $recent->filter('[data-rebuild-no-trace]')->text());
    }

    public function test_a_running_rebuild_of_a_projection_without_partitions_shows_zero_batches_and_is_not_failed(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true)];
        $this->orders->trackings = [$this->tracking('per_ticket', 'running', batchesDone: 0, batchCount: 0, elapsedMs: 1000)];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="per_ticket"]');
        self::assertSame('running', $row->filter('[data-rebuild-state]')->attr('data-rebuild-state'));
        self::assertSame('0%', $row->filter('[data-rebuild-percent]')->text());
        self::assertSame('0 of 0 batches', $row->filter('[data-rebuild-batches]')->text());
        self::assertSame('0 of 0 batches', $crawler->filter('[data-recent-rebuilds] [data-rebuild-progress]')->text());
    }

    public function test_a_rebuild_whose_failed_batch_was_replayed_shows_done_with_every_batch(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true)];
        $this->orders->trackings = [$this->tracking('per_ticket', 'done', batchesDone: 3, batchCount: 3, durationMs: 61000)];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="per_ticket"]');
        self::assertSame('done', $row->filter('[data-rebuild-state]')->attr('data-rebuild-state'));
        self::assertSame('in 1m 1s', $row->filter('[data-rebuild-duration]')->text());
        self::assertCount(0, $row->filter('[data-rebuild-missing], [data-rebuild-failed-batches]'));
        self::assertSame('3 of 3 batches', $crawler->filter('[data-recent-rebuilds] [data-rebuild-progress]')->text());
    }

    public function test_a_rebuild_started_from_the_console_is_shown_without_a_trace(): void
    {
        $client = $this->pulseWithOrders();
        $this->orders->projections = [$this->projection('per_ticket', partitioned: true)];
        $this->orders->trackings = [$this->tracking('per_ticket', 'running', batchesDone: 1, batchCount: 4, tracingId: null, startedFrom: 'console', missingBatches: [2, 3, 4], groupId: 'group-1')];

        $crawler = $this->observe($client);

        $row = $crawler->filter('tr[data-projection="per_ticket"]');
        self::assertSame('1 of 4 batches recorded', $row->filter('[data-rebuild-batches]')->text());
        self::assertCount(0, $row->filter('[data-rebuild-missing]'));
        self::assertSame('started from the console', $row->filter('[data-rebuild-started-from-console]')->text());
        self::assertCount(0, $crawler->filter('a[data-rebuild-trace]'));
        $recent = $crawler->filter('[data-recent-rebuilds] tr[data-rebuild="group-1"]');
        self::assertSame('from the console', $recent->filter('[data-rebuild-started-from-console]')->text());
        self::assertSame('no trace', $recent->filter('[data-rebuild-no-trace]')->text());
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
            public ?string $trackingsRefusal = null;

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
                if ($this->trackingsRefusal !== null) {
                    return json_encode(['requestKind' => 'ecotone.pulse.trackings.list', 'serviceName' => 'orders', 'tenant' => null, 'message' => $this->trackingsRefusal, 'queryId' => $queryId, 'tracingId' => null, 'failedAt' => gmdate('c')], JSON_THROW_ON_ERROR);
                }

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

    private function projection(string $name, bool $partitioned = false, int $events = 0, ?string $rebuildChannel = 'rebuild_channel', ?string $initialization = 'initialized', string $mode = 'synchronous', ?string $backfillChannel = null, bool $manualKickOff = false, ?string $rebuildBatchCompletionChannel = null): array
    {
        return [
            'name' => $name,
            'mode' => $mode,
            'partitioned' => $partitioned,
            'manualKickOff' => $manualKickOff,
            'asyncChannel' => null,
            'rebuildAsyncChannel' => $rebuildChannel,
            'backfillAsyncChannel' => $backfillChannel,
            'rebuildBatchCompletionChannel' => $rebuildBatchCompletionChannel ?? $rebuildChannel,
            'backfillBatchCompletionChannel' => $backfillChannel,
            'initialization' => $initialization,
            'positionDigest' => 'digest',
            'eventsBehind' => $partitioned ? null : ['events' => $events, 'atLeast' => false],
            'partitions' => $partitioned ? ['total' => 5, 'inspected' => 5, 'behind' => 0, 'atLeast' => false] : null,
            'unavailable' => null,
        ];
    }

    private function tracking(string $projection, string $state, int $batchesDone = 0, ?int $batchCount = null, ?int $elapsedMs = null, ?int $durationMs = null, ?string $deadLetterMessageId = null, ?string $tracingId = self::TRACE, string $type = 'projection-rebuild', string $startedFrom = 'pulse', array $missingBatches = [], array $failedBatches = [], ?string $groupId = null, array $batchesWaitingToBeRecorded = []): array
    {
        return [
            'batchesWaitingToBeRecorded' => $batchesWaitingToBeRecorded,
            'tracingId' => $tracingId,
            'startedFrom' => $startedFrom,
            'missingBatches' => $missingBatches,
            'failedBatches' => $failedBatches,
            ...($groupId === null ? [] : ['groupId' => $groupId]),
            'category' => 'ecotone-pulse',
            'type' => $type,
            'reference' => $projection,
            'tenant' => null,
            'startedAt' => gmdate('Y-m-d\TH:i:s.000000+00:00'),
            'state' => $state,
            'batchesDone' => $batchesDone,
            'batchCount' => $batchCount,
            ...match ($state) {
                'done', 'discarded', 'released' => ['durationMs' => $durationMs],
                'failed' => ['deadLetterMessageId' => $deadLetterMessageId, 'elapsedMs' => $elapsedMs],
                default => ['elapsedMs' => $elapsedMs],
            },
        ];
    }
}
