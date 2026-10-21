<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Tests\Support\MultiTenantServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class MultiTenantServiceTest extends ReleasingWebTestCase
{
    use MultiTenantServicesTrait;

    private const FIRST_TRACE = '0af7651916cd43dd8448eb211c80319c';
    private const SECOND_TRACE = '1bf7651916cd43dd8448eb211c80319d';

    public function test_pulse_discovers_the_tenants_of_a_service_without_any_tenant_configuration(): void
    {
        $client = $this->pulseWithTenants();

        $services = $this->refreshedView($client, '/');

        self::assertSame(['tenant_a', 'tenant_b'], $services->filter('[data-service="orders"] [data-tenants] [data-tenant]')->each(static fn (Crawler $tenant) => $tenant->attr('data-tenant')));
        self::assertCount(0, $services->filter('[data-service="billing"] [data-tenants]'));
        self::assertCount(2, $services->filter('[data-tenant-status="connected"]'));
    }

    public function test_the_dead_letters_page_lists_the_dead_letters_of_each_tenant_in_its_own_section(): void
    {
        $client = $this->pulseWithTenants();
        $this->failOrderOfTenant($this->services['orders'], 'tenant_a', 1);
        $this->failOrderOfTenant($this->services['orders'], 'tenant_b', 2);
        $this->failOrderOfTenant($this->services['orders'], 'tenant_b', 3);

        $list = $this->refreshedView($client, '/dead-letters');

        self::assertSame('1', $list->filter('[data-service-section="orders@tenant_a"] [data-service-total]')->text());
        self::assertSame('2', $list->filter('[data-service-section="orders@tenant_b"] [data-service-total]')->text());
        self::assertCount(1, $list->filter('[data-service-section="orders@tenant_a"] [data-dead-letter]'));
        self::assertCount(2, $list->filter('[data-service-section="orders@tenant_b"] [data-dead-letter]'));
        self::assertSame('tenant_a', $list->filter('[data-service-section="orders@tenant_a"] [data-tenant]')->attr('data-tenant'));
        self::assertSame('2', $client->request('GET', '/')->filter('[data-summary="dead-letters"] b')->text());
    }

    public function test_a_replay_runs_the_dead_letter_in_the_tenant_it_belongs_to(): void
    {
        $client = $this->pulseWithTenants();
        $this->failOrderOfTenant($this->services['orders'], 'tenant_a', 1);
        $this->failOrderOfTenant($this->services['orders'], 'tenant_b', 2);
        $list = $this->refreshedView($client, '/dead-letters');
        $this->tenantWork->failing = false;

        $client->request('POST', '/dead-letters/actions', ['action' => 'replay', 'scope' => 'ids', 'selected' => ['orders@tenant_b' => $this->selectionOf($list, 'orders@tenant_b')], 'targetChannel' => ['orders@tenant_b' => self::TENANT_WORK_CHANNEL]]);
        $this->deliver($client);
        $this->consumeTenantWork($this->services['orders']);

        self::assertSame(['tenant_b:2'], $this->tenantWork->handled);
        $after = $this->refreshedView($client, '/dead-letters');
        self::assertCount(0, $after->filter('[data-service-section="orders@tenant_b"] [data-dead-letter]'));
        self::assertCount(1, $after->filter('[data-service-section="orders@tenant_a"] [data-dead-letter]'));
    }

    public function test_deleting_dead_letters_of_one_tenant_leaves_the_other_tenant_untouched(): void
    {
        $client = $this->pulseWithTenants();
        $this->failOrderOfTenant($this->services['orders'], 'tenant_a', 1);
        $this->failOrderOfTenant($this->services['orders'], 'tenant_b', 2);
        $list = $this->refreshedView($client, '/dead-letters');

        $client->request('POST', '/dead-letters/actions', ['action' => 'delete', 'scope' => 'ids', 'confirm' => '1', 'selected' => ['orders@tenant_a' => $this->selectionOf($list, 'orders@tenant_a')]]);
        $this->deliver($client);

        $after = $this->refreshedView($client, '/dead-letters');
        self::assertCount(0, $after->filter('[data-service-section="orders@tenant_a"] [data-dead-letter]'));
        self::assertCount(1, $after->filter('[data-service-section="orders@tenant_b"] [data-dead-letter]'));
    }

    public function test_traces_are_listed_and_found_in_the_tenant_they_ran_for(): void
    {
        $client = $this->pulseWithTenants();
        $this->auditOfTenant($this->services['orders'], 'tenant_a', 1, self::FIRST_TRACE);
        $this->auditOfTenant($this->services['orders'], 'tenant_b', 2, self::SECOND_TRACE);

        $flowTypes = $this->refreshedView($client, '/traces');
        $client->request('GET', '/traces/orders@tenant_a/order.audit');
        $this->deliver($client);
        $list = $client->request('GET', '/traces/orders@tenant_a/order.audit');
        $client->request('GET', '/traces/trace/' . self::SECOND_TRACE);
        $this->deliver($client);
        $trace = $client->request('GET', '/traces/trace/' . self::SECOND_TRACE);

        self::assertCount(1, $flowTypes->filter('[data-flow-type="order.audit"][data-service="orders@tenant_a"]'));
        self::assertCount(1, $flowTypes->filter('[data-flow-type="order.audit"][data-service="orders@tenant_b"]'));
        self::assertSame([self::FIRST_TRACE], $list->filter('[data-trace]')->each(static fn (Crawler $row) => $row->attr('data-trace-id')));
        self::assertSame('tenant_a', $list->filter('[data-detail="tenant"]')->text());
        self::assertSame('2 spans', $this->spansOf($trace, 'orders@tenant_b'));
        self::assertSame('answered', $trace->filter('[data-service-state="orders@tenant_a"]')->attr('data-state'));
        self::assertStringNotContainsString('spans', $trace->filter('[data-service-state="orders@tenant_a"]')->text());
    }

    public function test_a_tenant_that_the_service_does_not_advertise_is_shown_as_such_and_never_asked_for(): void
    {
        $client = $this->pulseWithTenants(narrowing: '{tenants=tenant_a,tenant_x}');
        $this->failOrderOfTenant($this->services['orders'], 'tenant_b', 1);

        $hub = $this->refreshedView($client, '/services/orders');
        $list = $client->request('GET', '/dead-letters');

        self::assertSame(['tenant_a'], $hub->filter('[data-tenant]:not([data-tenant-unadvertised])')->each(static fn (Crawler $tenant) => $tenant->attr('data-tenant')));
        self::assertStringContainsString('not advertised by the service', $hub->filter('[data-tenant="tenant_x"][data-tenant-unadvertised]')->text());
        self::assertCount(0, $list->filter('[data-service-section="orders@tenant_b"]'));
        self::assertCount(1, $list->filter('[data-service-section="orders@tenant_a"]'));
    }

    public function test_the_doctor_and_the_banner_show_the_discovered_tenants(): void
    {
        $client = $this->pulseWithTenants();
        $this->refreshedView($client, '/');
        $application = new Application($client->getKernel());

        $doctor = new CommandTester($application->find('pulse:doctor'));
        $doctor->execute([]);
        $banner = new CommandTester($application->find('pulse:banner'));
        $banner->execute([]);

        self::assertStringContainsString('orders advertises tenant_a, tenant_b', $doctor->getDisplay());
        self::assertStringContainsString('tenants tenant_a|tenant_b', $banner->getDisplay());
    }

    public function test_a_single_tenant_service_shows_no_tenant_interface(): void
    {
        $client = $this->pulseWithTenants();

        $list = $this->refreshedView($client, '/dead-letters');
        $traces = $client->request('GET', '/traces');

        self::assertCount(0, $list->filter('[data-service-section="billing"] [data-tenant]'));
        self::assertCount(1, $list->filter('[data-service-section="billing"]'));
        self::assertCount(0, $traces->filter('[data-service-section="billing"] [data-tenant]'));
    }

    private function pulseWithTenants(string $narrowing = ''): KernelBrowser
    {
        $suffix = uniqid();
        $this->pulseChannel = 'pulse_' . $suffix;
        $this->serviceChannels = ['orders' => 'orders_' . $suffix, 'billing' => 'billing_' . $suffix];
        $this->configurePulse([
            'PULSE_CHANNEL' => $this->pulseChannel,
            'PULSE_SERVICES' => 'orders:' . $this->serviceChannels['orders'] . $narrowing . ',billing:' . $this->serviceChannels['billing'],
        ]);
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        $this->services = ['orders' => $this->multiTenantService('orders')];
        $this->services['billing'] = $this->serviceWithoutDeadLetters('billing');

        return $client;
    }

    private function refreshedView(KernelBrowser $client, string $uri): Crawler
    {
        $client->request('POST', '/refresh');
        $this->deliver($client);
        $client->request('POST', '/refresh');
        $this->deliver($client);

        return $client->request('GET', $uri);
    }

    private function deliver(KernelBrowser $client): void
    {
        $this->runAllServices();
        $this->runPulseWorker($client->getKernel());
    }

    /**
     * @return list<string>
     */
    private function selectionOf(Crawler $list, string $scope): array
    {
        return $list->filter(sprintf('[data-dead-letter][data-service="%1$s"] input[name="selected[%1$s][]"]', $scope))->each(static fn (Crawler $box) => $box->attr('value'));
    }

    private function spansOf(Crawler $trace, string $scope): string
    {
        return preg_match('/\((\d+ spans)\)/', $trace->filter(sprintf('[data-service-state="%s"]', $scope))->text(), $matches) === 1 ? $matches[1] : '';
    }
}
