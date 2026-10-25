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

    public function test_the_tile_shows_at_most_four_problems_and_says_how_many_more(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = array_map(fn (int $number): array => $this->channel('queue_' . $number, 2000), range(1, 6));

        $crawler = $this->observeBoard($client);

        self::assertCount(4, $crawler->filter('[data-tile="orders"] [data-problem]'));
        self::assertSame('2 more', trim($crawler->filter('[data-tile="orders"] [data-more-problems]')->text()));
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

    public function test_the_home_page_links_to_the_services_table(): void
    {
        $client = $this->boardWith(['orders' => null]);

        $crawler = $this->observeBoard($client);

        self::assertSame('/services', $crawler->filter('[data-all-services-link]')->attr('href'));
        self::assertSame('All services table', trim($crawler->filter('[data-all-services-link]')->text()));
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
