<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\ProblemBoardServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class HomeBoardTest extends ReleasingWebTestCase
{
    use ProblemBoardServicesTrait;

    public function test_a_service_without_problems_says_all_clear(): void
    {
        $client = $this->boardWith(['orders' => null]);

        $crawler = $this->observeBoard($client);

        self::assertSame('All clear', trim($crawler->filter('[data-tile="orders"] [data-all-clear]')->text()));
        self::assertSame('connected', $crawler->filter('[data-tile="orders"] [data-status]')->attr('data-status'));
    }

    public function test_a_service_that_has_not_answered_yet_is_waiting_not_all_clear(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filter('[data-tile="orders"] [data-waiting]'));
        self::assertCount(0, $crawler->filter('[data-tile="orders"] [data-all-clear]'));
    }

    public function test_tiles_are_ordered_unreachable_first_then_by_severity_then_problem_count_then_name(): void
    {
        $client = $this->boardWith(['alpha' => null, 'bravo' => null, 'charlie' => null, 'delta' => null, 'echo' => null]);
        $this->boardState['bravo']->deadLetters = ['total' => 1, 'groups' => [$this->failureGroup('A', 1)], 'recent' => []];
        $this->boardState['charlie']->channels = [$this->channel('invoices', 20000)];
        $this->boardState['delta']->channels = [$this->channel('invoices', 20000), $this->channel('emails', 30000)];
        $this->boardState['echo']->deadLetters = ['total' => 1, 'groups' => [$this->failureGroup('A', 1)], 'recent' => []];
        unset($this->boardServices['alpha']);

        $client->request('POST', '/refresh');
        $this->advanceTimeBy(10);
        $crawler = $this->observeBoard($client);

        self::assertSame(['alpha', 'delta', 'charlie', 'bravo', 'echo'], $this->tileOrder($crawler));
    }

    public function test_the_summary_strip_counts_critical_warning_and_healthy_tiles(): void
    {
        $client = $this->boardWith(['alpha' => null, 'bravo' => null, 'charlie' => null, 'delta' => null]);
        $this->boardState['alpha']->channels = [$this->channel('invoices', 20000)];
        $this->boardState['bravo']->deadLetters = ['total' => 1, 'groups' => [$this->failureGroup('A', 1)], 'recent' => []];

        $crawler = $this->observeBoard($client);

        self::assertSame('1', $crawler->filter('[data-summary="critical"] b')->text());
        self::assertSame('1', $crawler->filter('[data-summary="warning"] b')->text());
        self::assertSame('2', $crawler->filter('[data-summary="healthy"] b')->text());
    }

    public function test_the_summary_strip_explains_that_a_kept_failed_message_is_a_warning_and_what_makes_a_problem_critical(): void
    {
        $client = $this->boardWith(['alpha' => null]);
        $this->boardState['alpha']->deadLetters = ['total' => 1, 'groups' => [$this->failureGroup('A', 1)], 'recent' => []];

        $crawler = $this->observeBoard($client);

        $severity = $crawler->filter('[data-verdict-meta] [data-concept="severity"]');
        self::assertCount(1, $severity);
        self::assertStringContainsString('failed message', $severity->filter('[data-concept-what]')->text());
        self::assertStringContainsString('Critical', $severity->filter('[data-concept-what]')->text());
    }

    public function test_the_page_shows_at_most_three_problem_cards_worst_first_and_says_how_many_more(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [...array_map(fn (int $number): array => $this->channel('queue_' . $number, 2000), range(1, 5)), $this->channel('invoices', 20000)];

        $crawler = $this->observeBoard($client);

        $cards = $crawler->filter('[data-home-problems] [data-problem]');
        self::assertCount(3, $cards);
        self::assertSame(['critical', 'warning', 'warning'], $cards->each(static fn (Crawler $card): string => (string) $card->attr('data-problem-severity')));
        self::assertSame('invoices: 20 000 ready (critical)', $cards->first()->filter('[data-problem-text]')->text());
        self::assertSame(['orders', 'invoices'], $cards->first()->filter('[data-problem-where] li')->each(static fn (Crawler $place): string => $place->text()));
        self::assertSame('Open the channels', $cards->first()->filter('.pri')->text());
        self::assertSame('/services/orders/channels', $cards->first()->filter('.pri')->attr('href'));
        self::assertSame('3 more problems in the services below', trim($crawler->filter('[data-more-problems]')->text()));
        self::assertSame('#services', $crawler->filter('[data-more-problems] a')->attr('href'));
    }

    public function test_the_tile_lists_every_problem_of_its_service_collapsed_under_a_count(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [...array_map(fn (int $number): array => $this->channel('queue_' . $number, 2000), range(1, 5)), $this->channel('invoices', 20000)];

        $crawler = $this->observeBoard($client);

        $list = $crawler->filter('[data-tile="orders"] details[data-tile-problems]');
        self::assertNull($list->attr('open'));
        self::assertSame('6 problems · 1 critical, 5 warning', $list->filter('summary [data-summary-state]')->text());
        self::assertCount(6, $list->filter('[data-problem]'));
        self::assertCount(0, $crawler->filter('details details'));
    }

    public function test_the_page_opens_with_one_verdict_that_names_the_services_needing_attention(): void
    {
        $client = $this->boardWith(['alpha' => null, 'bravo' => null]);
        $this->boardState['bravo']->deadLetters = ['total' => 1, 'groups' => [$this->failureGroup('A', 1)], 'recent' => []];

        $crawler = $this->observeBoard($client);

        $verdict = $crawler->filter('[data-verdict]');
        self::assertCount(1, $verdict);
        self::assertSame('warning', $verdict->attr('data-verdict'));
        self::assertSame('1 of 2 services needs attention: bravo has 1 problem.', $verdict->filter('[data-verdict-sentence]')->text());
        $html = (string) $client->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'data-home-problems'), strpos($html, 'data-verdict='));
        self::assertLessThan(strpos($html, 'data-tile='), strpos($html, 'data-verdict='));
    }

    public function test_an_all_clear_verdict_names_what_it_checked_across_every_service(): void
    {
        $client = $this->boardWith(['alpha' => null, 'bravo' => null]);
        $this->boardState['alpha']->channels = [$this->channel('invoices', 3), $this->channel('emails', 0)];
        $this->boardState['bravo']->projections = [$this->projectionReport('totals')];

        $crawler = $this->observeBoard($client);

        self::assertSame('ok', $crawler->filter('[data-verdict]')->attr('data-verdict'));
        self::assertSame('All clear: 2 services answered, 2 channels, none stuck, no failed messages, 1 projection, none behind.', $crawler->filter('[data-verdict-sentence]')->text());
        self::assertCount(0, $crawler->filter('[data-problem]'));
    }

    public function test_each_tile_says_whether_the_service_is_connected_and_links_its_channels_failures_and_projections(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [$this->channel('invoices', 3)];
        $this->boardState['orders']->deadLetters = ['total' => 2, 'groups' => [$this->failureGroup('OrderPlaced', 2)], 'recent' => []];
        $this->boardState['orders']->projections = [$this->projectionReport('totals')];

        $crawler = $this->observeBoard($client);

        $tile = $crawler->filter('[data-tile="orders"]');
        self::assertSame('ok', $tile->filter('[data-status] [data-status-pill]')->attr('data-status-pill'));
        self::assertSame('Connected', $tile->filter('[data-status] [data-status-pill]')->text());
        self::assertSame([
            ['channels', '1 channel · none stuck', '/services/orders/channels'],
            ['dead-letters', '2 failed messages · in 1 failure group', '/dead-letters?service=orders'],
            ['projections', '1 projection · up to date', '/services/orders/projections'],
        ], $tile->filter('[data-tile-fact]')->each(static fn (Crawler $fact): array => [$fact->attr('data-tile-fact'), trim($fact->filter('a')->text()), $fact->filter('a')->attr('href')]));
    }

    public function test_a_first_visit_introduction_explains_what_pulse_watches_and_stays_closed_once_dismissed(): void
    {
        $client = $this->boardWith(['orders' => null]);

        $crawler = $this->observeBoard($client);

        $introduction = $crawler->filter('[data-hint="home-introduction"]');
        self::assertCount(1, $introduction);
        self::assertSame('hint.home-introduction', $introduction->filter('details')->attr('data-disclosure'));
        self::assertNotNull($introduction->filter('details')->attr('open'));
        self::assertCount(1, $introduction->filter('[data-concept="service"]'));
        self::assertCount(1, $crawler->filter('[data-updated] [data-concept="snapshot"]'));
        self::assertCount(1, $crawler->filter('[data-intro]'));
    }

    public function test_a_multi_tenant_service_has_one_tile_per_tenant_and_a_single_tenant_one_has_one(): void
    {
        $client = $this->boardWith(['orders' => ['tenant_a', 'tenant_b'], 'billing' => null]);
        $this->boardState['orders']->tenantSections = [
            'tenant_a' => ['channels' => [], 'deadLetters' => ['total' => 0, 'groups' => [], 'recent' => []], 'projections' => []],
            'tenant_b' => ['channels' => [], 'deadLetters' => ['total' => 1, 'groups' => [$this->failureGroup('A', 1)], 'recent' => []], 'projections' => []],
        ];

        $crawler = $this->observeBoard($client);

        self::assertSame(['orders@tenant_b', 'billing', 'orders@tenant_a'], $this->tileOrder($crawler));
        self::assertSame('tenant_b', $crawler->filter('[data-tile="orders@tenant_b"]')->attr('data-tile-tenant'));
        self::assertSame('/services/orders?tenant=tenant_b', $crawler->filter('[data-tile="orders@tenant_b"] [data-tile-link]')->attr('href'));
        self::assertSame('/services/billing', $crawler->filter('[data-tile="billing"] [data-tile-link]')->attr('href'));
    }

    public function test_refreshing_from_the_home_page_returns_to_it(): void
    {
        $client = $this->boardWith(['orders' => null]);

        $client->request('POST', '/refresh', ['return' => 'home']);

        self::assertResponseRedirects('/');
    }

    /**
     * @return list<string>
     */
    private function tileOrder(Crawler $crawler): array
    {
        return $crawler->filter('[data-tile]')->each(static fn (Crawler $tile): string => $tile->attr('data-tile'));
    }
}
