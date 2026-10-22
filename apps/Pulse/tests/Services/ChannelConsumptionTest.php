<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\ProblemBoardServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;

/**
 * @internal
 */
final class ChannelConsumptionTest extends ReleasingWebTestCase
{
    use ProblemBoardServicesTrait;

    public function test_a_channel_with_ready_messages_on_its_first_snapshot_is_not_flagged(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [$this->channel('invoices', 50)];

        $crawler = $this->observeBoard($client);

        self::assertSame([], $this->notDrainingOf($crawler));
    }

    public function test_a_channel_whose_ready_count_has_not_gone_down_for_longer_than_five_minutes_is_not_draining(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [$this->channel('invoices', 50)];
        $this->settleBoard($client);

        $this->advanceTimeBy(300);
        $atLimit = $this->observeBoard($client);
        $this->advanceTimeBy(1);
        $pastLimit = $this->observeBoard($client);

        self::assertSame([], $this->notDrainingOf($atLimit));
        self::assertSame([['text' => 'invoices: 50 ready, count not falling for 5 min. Start `ecotone:run invoices` (handles OrderPlaced → notifyCustomer)', 'href' => '/services/orders#channels', 'severity' => 'warning', 'kind' => 'channel-not-draining']], $this->notDrainingOf($pastLimit));
    }

    public function test_a_ready_count_that_goes_down_resets_the_clock(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [$this->channel('invoices', 50)];
        $this->settleBoard($client);
        $this->advanceTimeBy(200);
        $this->boardState['orders']->channels = [$this->channel('invoices', 49)];
        $this->settleBoard($client);
        $this->advanceTimeBy(200);

        $crawler = $this->observeBoard($client);

        self::assertSame([], $this->notDrainingOf($crawler));
    }

    public function test_a_ready_count_that_keeps_growing_is_still_not_draining(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [$this->channel('invoices', 50)];
        $this->settleBoard($client);
        $this->advanceTimeBy(200);
        $this->boardState['orders']->channels = [$this->channel('invoices', 80)];
        $this->settleBoard($client);
        $this->advanceTimeBy(200);

        $crawler = $this->observeBoard($client);

        self::assertSame(['invoices: 80 ready, count not falling for 6 min. Start `ecotone:run invoices` (handles OrderPlaced → notifyCustomer)'], array_column($this->notDrainingOf($crawler), 'text'));
    }

    public function test_a_channel_with_nothing_ready_is_never_flagged_and_starts_a_new_clock_when_messages_return(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [$this->channel('invoices', 0)];
        $this->settleBoard($client);
        $this->advanceTimeBy(3600);
        $this->boardState['orders']->channels = [$this->channel('invoices', 5)];

        $crawler = $this->observeBoard($client);

        self::assertSame([], $this->notDrainingOf($crawler));
    }

    public function test_a_channel_without_a_polling_consumer_says_so(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [[...$this->channel('invoices', 50), 'consumerToRun' => null, 'handlers' => []]];
        $this->settleBoard($client);
        $this->advanceTimeBy(301);

        $crawler = $this->observeBoard($client);

        self::assertSame(['invoices: 50 ready, count not falling for 5 min. No polling consumer runs this channel'], array_column($this->notDrainingOf($crawler), 'text'));
    }

    public function test_the_stalled_limit_follows_the_environment(): void
    {
        $client = $this->boardWith(['orders' => null], ['PULSE_CHANNEL_STALLED_AFTER' => '60']);
        $this->boardState['orders']->channels = [$this->channel('invoices', 50)];
        $this->settleBoard($client);
        $this->advanceTimeBy(61);

        $crawler = $this->observeBoard($client);

        self::assertSame(['invoices: 50 ready, count not falling for 1 min. Start `ecotone:run invoices` (handles OrderPlaced → notifyCustomer)'], array_column($this->notDrainingOf($crawler), 'text'));
    }

    public function test_the_service_page_lists_the_consumer_to_run_and_the_handlers_and_marks_the_channel_not_draining(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [$this->channel('invoices', 50), [...$this->channel('emails', 0), 'consumerToRun' => null, 'handlers' => []]];
        $this->settleBoard($client);
        $this->advanceTimeBy(301);
        $this->observeBoard($client);

        $crawler = $client->request('GET', '/services/orders');

        self::assertSame('ecotone:run invoices', $crawler->filter('tr[data-channel="invoices"] [data-run-command]')->text());
        self::assertSame(['OrderPlaced → notifyCustomer'], $crawler->filter('tr[data-channel="invoices"] [data-channel-handlers] li')->extract(['_text']));
        self::assertSame('No polling consumer', $crawler->filter('tr[data-channel="emails"] [data-run-command]')->text());
        self::assertSame('Not draining for 5 min', trim($crawler->filter('tr[data-channel="invoices"] [data-not-draining]')->text()));
        self::assertCount(0, $crawler->filter('tr[data-channel="emails"] [data-not-draining]'));
    }

    public function test_a_channel_whose_setup_is_unknown_is_not_reported_as_not_set_up(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [[...$this->channel('invoices', 0), 'setUp' => null, 'probeError' => 'Socket error: could not connect to host']];

        $crawler = $this->observeBoard($client);

        self::assertSame([], array_values(array_filter($this->problemsOf($crawler, 'orders'), static fn (array $problem): bool => $problem['kind'] === 'channel-not-set-up')));
        self::assertCount(0, $client->request('GET', '/services/orders')->filter('[data-channel-not-set-up]'));
    }

    public function test_a_channel_the_broker_does_not_have_is_marked_not_set_up_on_its_row_and_on_the_board(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [[...$this->channel('invoices', 0), 'setUp' => false]];

        $crawler = $this->observeBoard($client);

        self::assertSame(['Channel invoices not set up'], array_column(array_filter($this->problemsOf($crawler, 'orders'), static fn (array $problem): bool => $problem['kind'] === 'channel-not-set-up'), 'text'));
        self::assertCount(1, $client->request('GET', '/services/orders')->filter('tr[data-channel="invoices"] [data-channel-not-set-up]'));
    }

    /**
     * @return list<array{text: string, href: string, severity: string, kind: string}>
     */
    private function notDrainingOf(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return array_values(array_filter($this->problemsOf($crawler, 'orders'), static fn (array $problem): bool => $problem['kind'] === 'channel-not-draining'));
    }
}
