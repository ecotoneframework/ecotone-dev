<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Tests\Support\FailureRepliesTrait;
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
    use FailureRepliesTrait;

    public function test_an_initialization_the_service_refused_in_one_tenant_shows_its_message_for_that_tenant_only(): void
    {
        $client = $this->pulseWithOrderPlaced();
        $this->act($client, 'initialize', 'tenant_kickoff_orders', 'tenant_a');

        $client->request('POST', '/services/orders/projections/tenant_kickoff_orders/initialize', ['confirm' => '1', 'tenant' => 'tenant_a']);
        $this->deliver($client);
        $page = $client->followRedirect();

        self::assertStringContainsString('is already initialized', $page->filter('[data-notice-error][data-service-refusal="orders@tenant_a"]')->text());
        self::assertCount(0, $page->filter('[data-service-refusal="orders@tenant_b"]'));
        self::assertCount(0, $page->filter('[data-notice]'));
        self::assertSame(['1'], $this->tenantKickoffProjections->projected);
    }

    public function test_a_backfill_the_service_refused_for_one_tenant_does_not_show_for_the_other_tenant(): void
    {
        $client = $this->pulseWithOrderPlaced();

        $client->request('POST', '/services/orders/projections/tenant_kickoff_orders/backfill', ['confirm' => '1', 'tenant' => 'tenant_b']);
        $this->serviceRefuses('orders', 'ecotone.pulse.projections.backfill', 'backfill refused for b', tenant: 'tenant_b');
        $this->runPulseWorker($client->getKernel());
        $page = $client->followRedirect();

        self::assertStringContainsString('backfill refused for b', $page->filter('[data-service-refusal="orders@tenant_b"]')->text());
        self::assertCount(0, $page->filter('[data-service-refusal="orders@tenant_a"]'));
    }

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

    public function test_a_backfill_in_one_tenant_projects_and_is_tracked_in_that_tenant_only(): void
    {
        $client = $this->pulseWithOrderPlaced();
        $this->initializeInTenant('tenant_kickoff_orders', 'tenant_a');
        $this->initializeInTenant('tenant_kickoff_orders', 'tenant_b');
        $this->tenantKickoffProjections->projected = [];
        $this->services['orders']->withEventStream('tenant_orders_stream', [Event::createWithType('order.placed', ['orderId' => '2'])]);

        $page = $this->act($client, 'backfill', 'tenant_kickoff_orders', 'tenant_b');

        self::assertSame(['1', '2'], $this->tenantKickoffProjections->projected);
        self::assertSame([], $this->operationsOf($page, 'tenant_a'));
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

    public function test_a_backfill_of_a_tenant_returns_to_the_page_of_that_tenant_and_names_it_in_the_notice(): void
    {
        $client = $this->pulseWithOrderPlaced();
        $this->initializeInTenant('tenant_kickoff_orders', 'tenant_b');

        $client->request('POST', '/services/orders/projections/tenant_kickoff_orders/backfill', ['confirm' => '1', 'tenant' => 'tenant_b']);
        $this->deliver($client);

        self::assertSame('/services/orders@tenant_b/projections?backfill=requested&projection=tenant_kickoff_orders', $client->getResponse()->headers->get('Location'));
        $page = $client->followRedirect();
        self::assertStringContainsString('Backfill of tenant_kickoff_orders requested in tenant tenant_b', $page->filter('[data-notice]')->text());
        self::assertCount(0, $page->filter('[data-projections-section="orders@tenant_a"]'));
    }

    public function test_an_action_that_is_not_confirmed_returns_to_the_page_of_the_tenant_it_was_asked_in(): void
    {
        $client = $this->pulseWithOrderPlaced();

        $client->request('POST', '/services/orders/projections/tenant_orders/rebuild', ['tenant' => 'tenant_b']);

        self::assertSame('/services/orders@tenant_b/projections?rebuild=not_confirmed&projection=tenant_orders', $client->getResponse()->headers->get('Location'));
        self::assertStringContainsString('in tenant tenant_b was not confirmed', $client->followRedirect()->filter('[data-notice-error]')->text());
    }

    private function initializeInTenant(string $projection, string $tenant): void
    {
        $this->services['orders']->sendCommandWithRouting('projection.initialize', $projection, metadata: ['tenant' => $tenant]);
    }

    private function pulseWithOrderPlaced(): KernelBrowser
    {
        $client = $this->pulseWithTenants();
        $this->services['orders']->withEventStream('tenant_orders_stream', [Event::createWithType('order.placed', ['orderId' => '1'])]);
        foreach (['tenant_a', 'tenant_b'] as $tenant) {
            $this->initializeInTenant('tenant_orders', $tenant);
        }
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
