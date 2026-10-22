<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Tests\Support\MultiTenantServicesTrait;
use App\Tests\Support\OpenedConnections;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class TenantViewsTest extends ReleasingWebTestCase
{
    use MultiTenantServicesTrait;

    private const QUEUE = 'pulse_tenant_queue';

    protected function tearDown(): void
    {
        @unlink(self::unpreparedDatabaseFile());
        parent::tearDown();
    }

    public function test_the_depth_of_a_channel_is_measured_per_tenant(): void
    {
        $client = $this->pulseWithTenants();
        $this->queueOrders('tenant_a', 3);
        $this->queueOrders('tenant_b', 1);

        $hub = $this->refreshedView($client, '/services/orders');

        self::assertSame('3', $hub->filter('[data-hub-tenants] tr[data-channel="' . self::QUEUE . '"][data-tenant="tenant_a"] [data-ready]')->text());
        self::assertSame('1', $hub->filter('[data-hub-tenants] tr[data-channel="' . self::QUEUE . '"][data-tenant="tenant_b"] [data-ready]')->text());
    }

    public function test_a_channel_is_not_consuming_in_the_tenant_whose_ready_count_stopped_going_down_only(): void
    {
        $client = $this->pulseWithTenants();
        $this->queueOrders('tenant_a', 3);
        $this->queueOrders('tenant_b', 3);
        $this->refreshedView($client, '/');
        $this->advanceTimeBy(200);
        $consumed = $this->consumeOneOrderFromTheQueue();
        $idle = $consumed === 'tenant_a' ? 'tenant_b' : 'tenant_a';
        $this->refreshedView($client, '/');
        $this->advanceTimeBy(200);

        $home = $this->refreshedView($client, '/');

        self::assertSame(['/services/orders?tenant=' . $idle . '#channels'], $this->problemsOf($home, 'orders@' . $idle, 'channel-not-consuming', self::QUEUE));
        self::assertSame([], $this->problemsOf($home, 'orders@' . $consumed, 'channel-not-consuming', self::QUEUE));
    }

    public function test_tables_missing_in_one_tenant_are_reported_for_that_tenant_only(): void
    {
        $client = $this->pulseWithUnpreparedTenantB();

        $home = $this->refreshedView($client, '/');
        $hubOfTenantA = $client->request('GET', '/services/orders?tenant=tenant_a');
        $hubOfTenantB = $client->request('GET', '/services/orders?tenant=tenant_b');

        self::assertSame([], $this->problemsOf($home, 'orders@tenant_a', 'table-missing'));
        self::assertNotSame([], $this->problemsOf($home, 'orders@tenant_b', 'table-missing'));
        self::assertCount(0, $hubOfTenantA->filter('[data-hub-setup] [data-setup-missing]'));
        self::assertGreaterThan(0, $hubOfTenantB->filter('[data-hub-setup] [data-setup-missing]')->count());
    }

    public function test_the_home_board_and_the_tenant_pages_show_the_dead_letters_of_their_own_tenant(): void
    {
        $client = $this->pulseWithTenants();
        $this->failOrderOfTenant($this->services['orders'], 'tenant_a', 1);
        $this->failOrderOfTenant($this->services['orders'], 'tenant_a', 2);

        $home = $this->refreshedView($client, '/');
        $hubOfTenantA = $client->request('GET', '/services/orders?tenant=tenant_a');
        $hubOfTenantB = $client->request('GET', '/services/orders?tenant=tenant_b');

        self::assertCount(1, $this->problemsOf($home, 'orders@tenant_a', 'dead-letters'));
        self::assertSame([], $this->problemsOf($home, 'orders@tenant_b', 'dead-letters'));
        self::assertStringContainsString('2 failures in order.place', $hubOfTenantA->filter('[data-hub-dead-letters] [data-failure]')->text());
        self::assertCount(0, $hubOfTenantB->filter('[data-hub-dead-letters] [data-failure]'));
    }

    private function pulseWithUnpreparedTenantB(): KernelBrowser
    {
        return $this->pulseWithTenantServices('', OpenedConnections::factory('sqlite:///' . self::unpreparedDatabaseFile()));
    }

    private static function unpreparedDatabaseFile(): string
    {
        return sys_get_temp_dir() . '/ecotone_pulse_unprepared_tenant_b_' . getmypid() . '.db';
    }

    private function queueOrders(string $tenant, int $count): void
    {
        for ($order = 1; $order <= $count; $order++) {
            $this->services['orders']->sendCommandWithRouting('order.queue', ['orderId' => $order], metadata: ['tenant' => $tenant]);
        }
    }

    private function consumeOneOrderFromTheQueue(): string
    {
        $this->services['orders']->runConsoleCommand('ecotone:run', ['consumerName' => self::QUEUE, 'handledMessageLimit' => 1, 'executionTimeLimit' => 1000]);
        self::assertCount(1, $this->tenantWork->queued);

        return explode(':', $this->tenantWork->queued[0])[0];
    }

    /**
     * @return list<string>
     */
    private function problemsOf(Crawler $home, string $tile, string $kind, ?string $about = null): array
    {
        self::assertSame('connected', $home->filter(sprintf('[data-tile="%s"] [data-status]', $tile))->attr('data-status'));

        $links = $home->filter(sprintf('[data-tile="%s"] [data-problem="%s"] a', $tile, $kind))->reduce(static fn (Crawler $link): bool => $about === null || str_contains($link->text(), $about));

        return $links->each(static fn (Crawler $link): string => (string) $link->attr('href'));
    }
}
