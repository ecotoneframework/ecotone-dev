<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Tests\Support\MultiTenantServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\EventSourcing\Event;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class TenantProjectionActionsTest extends ReleasingWebTestCase
{
    use MultiTenantServicesTrait;

    public function test_a_rebuild_in_one_tenant_replays_and_is_tracked_in_that_tenant_only(): void
    {
        $client = $this->pulseWithOrderPlaced();

        $page = $this->act($client, 'rebuild', 'tenant_orders', 'tenant_a');

        self::assertSame(['1'], $this->tenantProjections->projected);
        self::assertSame(['done'], $this->rebuildStates($page, 'tenant_a'));
        self::assertCount(1, $page->filter('[data-projections-section="orders@tenant_a"] [data-recent-rebuilds] tr[data-rebuild]'));
        self::assertCount(0, $page->filter('[data-projections-section="orders@tenant_b"] [data-recent-rebuilds]'));
    }

    public function test_a_rebuild_in_the_other_tenant_leaves_the_first_tenant_without_one(): void
    {
        $client = $this->pulseWithOrderPlaced();

        $page = $this->act($client, 'rebuild', 'tenant_orders', 'tenant_b');

        self::assertSame(['1'], $this->tenantProjections->projected);
        self::assertSame(['done'], $this->rebuildStates($page, 'tenant_b'));
        self::assertSame([], $this->rebuildStates($page, 'tenant_a'));
    }

    public function test_a_rebuild_stays_under_its_tenant_after_pulse_loses_its_own_state(): void
    {
        $client = $this->pulseWithOrderPlaced();
        $this->act($client, 'rebuild', 'tenant_orders', 'tenant_a');

        $this->resetPulseState($client);
        $page = $this->projectionsPage($client);

        self::assertSame(['done'], $this->rebuildStates($page, 'tenant_a'));
        self::assertSame([], $this->rebuildStates($page, 'tenant_b'));
    }

    public function test_an_initialization_in_one_tenant_is_tracked_in_that_tenant_only(): void
    {
        $client = $this->pulseWithOrderPlaced();

        $page = $this->act($client, 'initialize', 'tenant_kickoff_orders', 'tenant_a');

        self::assertSame(['1'], $this->tenantKickoffProjections->projected);
        self::assertSame(['done'], $this->rebuildStates($page, 'tenant_a'));
        self::assertSame([], $this->rebuildStates($page, 'tenant_b'));
        self::assertSame(['Initialization'], $this->operationsOf($page, 'tenant_a'));
        self::assertSame([], $this->operationsOf($page, 'tenant_b'));
    }

    public function test_a_backfill_in_one_tenant_projects_and_is_tracked_in_that_tenant_only(): void
    {
        $client = $this->pulseWithOrderPlaced();
        $this->act($client, 'initialize', 'tenant_kickoff_orders', 'tenant_a');
        $this->tenantKickoffProjections->projected = [];
        $this->services['orders']->withEventStream('tenant_orders_stream', [Event::createWithType('order.placed', ['orderId' => '2'])]);

        $page = $this->act($client, 'backfill', 'tenant_kickoff_orders', 'tenant_b');

        self::assertSame(['2'], $this->tenantKickoffProjections->projected);
        self::assertSame(['Initialization'], $this->operationsOf($page, 'tenant_a'));
        self::assertSame(['Backfill'], $this->operationsOf($page, 'tenant_b'));
    }

    public function test_an_action_naming_a_tenant_the_service_does_not_advertise_projects_nothing(): void
    {
        $client = $this->pulseWithOrderPlaced();
        $this->refreshedView($client, '/services/orders/projections');

        $client->request('POST', '/services/orders/projections/tenant_orders/rebuild', ['confirm' => '1', 'tenant' => 'tenant_x']);
        $this->deliver($client);
        $page = $client->followRedirect();

        self::assertSame([], $this->tenantProjections->projected);
        self::assertStringContainsString('does not report a projection "tenant_orders"', $page->filter('[data-notice-error]')->text());
    }

    private function pulseWithOrderPlaced(): KernelBrowser
    {
        $client = $this->pulseWithTenants();
        $this->services['orders']->withEventStream('tenant_orders_stream', [Event::createWithType('order.placed', ['orderId' => '1'])]);
        $this->refreshedView($client, '/services/orders/projections');

        return $client;
    }

    private function act(KernelBrowser $client, string $action, string $projection, string $tenant): Crawler
    {
        $client->request('POST', sprintf('/services/orders/projections/%s/%s', $projection, $action), ['confirm' => '1', 'tenant' => $tenant]);
        $this->deliver($client);

        return $this->projectionsPage($client);
    }

    private function projectionsPage(KernelBrowser $client): Crawler
    {
        $this->advanceTimeBy(60);
        $this->refreshedView($client, '/services/orders/projections');
        $this->deliver($client);

        return $client->request('GET', '/services/orders/projections');
    }

    /**
     * @return list<string>
     */
    private function rebuildStates(Crawler $page, string $tenant): array
    {
        return $page->filter(sprintf('[data-projections-section="orders@%s"] [data-recent-rebuilds] tr[data-rebuild] td:nth-child(4)', $tenant))->each(static fn (Crawler $cell): string => trim($cell->text()));
    }

    /**
     * @return list<string>
     */
    private function operationsOf(Crawler $page, string $tenant): array
    {
        return $page->filter(sprintf('[data-projections-section="orders@%s"] [data-recent-rebuilds] [data-operation-type]', $tenant))->each(static fn (Crawler $cell): string => trim($cell->text()));
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
}
