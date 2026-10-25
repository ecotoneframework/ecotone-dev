<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\ProblemBoardServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @internal
 */
final class SetupStatusTest extends ReleasingWebTestCase
{
    use ProblemBoardServicesTrait;

    private const CONNECTION = 'Ecotone\Api\Dbal\DbalConnectionFactory';

    public function test_a_service_whose_tables_and_channels_are_all_set_up_says_so(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->setup = ['tables' => [$this->table('message_queue', true), $this->table('dead_letter', true)]];
        $this->boardState['orders']->channels = [[...$this->channel('invoices', 0), 'setUp' => true]];

        $this->observeBoard($client);
        $crawler = $client->request('GET', '/services/orders');

        self::assertCount(1, $crawler->filter('[data-hub-setup] [data-setup-ok]'));
        self::assertCount(0, $crawler->filter('[data-hub-setup] [data-setup-missing]'));
        self::assertSame([], $this->problemsOf($this->observeBoard($client), 'orders'));
    }

    public function test_a_table_that_is_set_up_shows_no_command_to_create_it(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->setup = ['tables' => [$this->table('message_queue', true), $this->table('dead_letter', false)]];

        $this->observeBoard($client);
        $crawler = $client->request('GET', '/services/orders');

        self::assertCount(0, $crawler->filter('[data-setup-item="table:message_queue"] [data-fix]'));
        self::assertCount(1, $crawler->filter('[data-setup-item="table:dead_letter"] [data-fix]'));
    }

    public function test_a_missing_table_names_the_command_that_creates_it_and_is_a_critical_problem_linking_to_the_setup(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->setup = ['tables' => [$this->table('message_queue', false), $this->table('dead_letter', true)]];

        $home = $this->observeBoard($client);
        $crawler = $client->request('GET', '/services/orders');

        self::assertSame([['text' => 'Table for message_queue missing on ' . self::CONNECTION, 'href' => '/services/orders#setup', 'severity' => 'critical', 'kind' => 'table-missing']], $this->problemsOf($home, 'orders'));
        $item = $crawler->filter('[data-hub-setup] [data-setup-item="table:message_queue"]');
        self::assertSame('Missing', trim($item->filter('[data-setup-status]')->text()));
        self::assertSame("bin/console ecotone:migration:database:setup --initialize --feature=message_queue --connection='Ecotone\\Api\\Dbal\\DbalConnectionFactory'", trim($item->filter('[data-fix]')->text()));
        self::assertSame('Set up', trim($crawler->filter('[data-setup-item="table:dead_letter"] [data-setup-status]')->text()));
    }

    public function test_a_broker_channel_that_is_not_set_up_names_the_command_that_creates_it(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [[...$this->channel('invoices', 0), 'setUp' => false], [...$this->channel('emails', 0), 'setUp' => true]];

        $home = $this->observeBoard($client);
        $crawler = $client->request('GET', '/services/orders');

        self::assertSame([['text' => 'Channel invoices not set up', 'href' => '/services/orders#setup', 'severity' => 'critical', 'kind' => 'channel-not-set-up']], $this->problemsOf($home, 'orders'));
        self::assertSame('bin/console ecotone:migration:channel:setup --initialize --channel=invoices', trim($crawler->filter('[data-setup-item="channel:invoices"] [data-fix]')->text()));
        self::assertCount(0, $crawler->filter('[data-setup-item="channel:emails"] [data-fix]'));
    }

    public function test_a_section_the_service_could_not_read_is_listed_with_its_reason(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->sectionErrors = ['deadLetters' => "The 'ecotone_error_messages' table does not exist."];

        $home = $this->observeBoard($client);
        $crawler = $client->request('GET', '/services/orders');

        self::assertSame([['text' => "deadLetters could not be read: The 'ecotone_error_messages' table does not exist.", 'href' => '/services/orders#setup', 'severity' => 'warning', 'kind' => 'section-failed']], $this->problemsOf($home, 'orders'));
        self::assertStringContainsString("'ecotone_error_messages' table does not exist", $crawler->filter('[data-hub-setup] [data-section-error="deadLetters"]')->text());
    }

    public function test_the_fix_command_follows_the_framework_the_service_runs_on(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->setup = ['tables' => [$this->table('message_queue', false)]];
        $this->boardState['orders']->framework = 'laravel';

        $this->observeBoard($client);
        $crawler = $client->request('GET', '/services/orders');

        self::assertStringStartsWith('php artisan ecotone:migration:database:setup --initialize', trim($crawler->filter('[data-setup-item="table:message_queue"] [data-fix]')->text()));
    }

    public function test_a_service_without_a_console_gets_the_fix_as_a_console_command_call(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [[...$this->channel('invoices', 0), 'setUp' => false]];
        $this->boardState['orders']->framework = 'lite';

        $this->observeBoard($client);
        $crawler = $client->request('GET', '/services/orders');

        self::assertSame("\$ecotone->runConsoleCommand('ecotone:migration:channel:setup', ['initialize' => true, 'channel' => ['invoices']]);", trim($crawler->filter('[data-setup-item="channel:invoices"] [data-fix]')->text()));
    }

    public function test_tables_on_the_tenant_connection_are_reported_for_the_tenant_they_are_missing_in_only(): void
    {
        $client = $this->boardWith(['orders' => ['tenant_a', 'tenant_b']]);
        $this->boardState['orders']->setup = ['tables' => [$this->table('dead_letter', true)]];
        $this->boardState['orders']->tenantSections = [
            'tenant_a' => ['channels' => [], 'setup' => ['tenantTables' => [$this->table('message_queue', false)]]],
            'tenant_b' => ['channels' => [], 'setup' => ['tenantTables' => [$this->table('message_queue', true)]]],
        ];

        $home = $this->observeBoard($client);
        $ofTenantA = $client->request('GET', '/services/orders?tenant=tenant_a');
        $ofTenantB = $client->request('GET', '/services/orders?tenant=tenant_b');

        self::assertSame(['table-missing'], array_column($this->problemsOf($home, 'orders@tenant_a'), 'kind'));
        self::assertSame([], $this->problemsOf($home, 'orders@tenant_b'));
        self::assertCount(1, $ofTenantA->filter('[data-setup-item="table:message_queue"] [data-fix]'));
        self::assertSame('Set up', $this->statusOf($ofTenantB, 'table:message_queue'));
        self::assertSame('Set up', $this->statusOf($ofTenantA, 'table:dead_letter'));
    }

    public function test_an_unavailable_setup_connection_is_unknown_on_the_service_page_and_a_warning_on_the_home_board(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->setup = ['tables' => [$this->table('message_queue', true), $this->unknownTable('document_store', 'reporting', 'connection refused')]];

        $home = $this->observeBoard($client);
        $crawler = $client->request('GET', '/services/orders');

        self::assertSame([['text' => 'Setup of reporting could not be checked: connection refused', 'href' => '/services/orders#setup', 'severity' => 'warning', 'kind' => 'setup-unknown']], $this->problemsOf($home, 'orders'));
        self::assertCount(0, $crawler->filter('[data-hub-setup] [data-setup-ok]'));
        self::assertSame('Unknown', $this->statusOf($crawler, 'table:document_store'));
        self::assertStringContainsString('connection refused', $crawler->filter('[data-setup-item="table:document_store"] [data-setup-error]')->text());
    }

    public function test_a_channel_whose_probe_failed_does_not_support_the_everything_exists_claim(): void
    {
        $client = $this->boardWith(['orders' => null]);
        $this->boardState['orders']->channels = [[...$this->channel('invoices', 0), 'probeError' => 'connection refused', 'setUp' => null]];

        $home = $this->observeBoard($client);
        $crawler = $client->request('GET', '/services/orders');

        self::assertSame(['setup-unknown'], array_column($this->problemsOf($home, 'orders'), 'kind'));
        self::assertCount(0, $crawler->filter('[data-hub-setup] [data-setup-ok]'));
        self::assertSame('Unknown', $this->statusOf($crawler, 'channel:invoices'));
    }

    public function test_an_unavailable_tenant_connection_is_unknown_for_that_tenant_only(): void
    {
        $client = $this->boardWith(['orders' => ['tenant_a', 'tenant_b']]);
        $this->boardState['orders']->tenantSections = [
            'tenant_a' => ['channels' => [], 'setup' => ['tenantTables' => [$this->unknownTable('message_queue', 'tenant_a_connection', 'connection refused')]]],
            'tenant_b' => ['channels' => [], 'setup' => ['tenantTables' => [$this->table('message_queue', true)]]],
        ];

        $home = $this->observeBoard($client);
        $ofTenantA = $client->request('GET', '/services/orders?tenant=tenant_a');
        $ofTenantB = $client->request('GET', '/services/orders?tenant=tenant_b');

        self::assertSame([['text' => 'Setup of tenant_a_connection could not be checked: connection refused', 'href' => '/services/orders?tenant=tenant_a#setup', 'severity' => 'warning', 'kind' => 'setup-unknown']], $this->problemsOf($home, 'orders@tenant_a'));
        self::assertSame([], $this->problemsOf($home, 'orders@tenant_b'));
        self::assertCount(0, $ofTenantA->filter('[data-setup-ok]'));
        self::assertSame('Unknown', $this->statusOf($ofTenantA, 'table:message_queue'));
        self::assertCount(1, $ofTenantB->filter('[data-setup-ok]'));
    }

    private function statusOf(Crawler $crawler, string $item): string
    {
        return trim($crawler->filter(sprintf('[data-setup-item="%s"] [data-setup-status]', $item))->text());
    }

    /**
     * @return array{feature: string, connection: string, initialized: bool, error: null}
     */
    private function table(string $feature, bool $initialized): array
    {
        return ['feature' => $feature, 'connection' => self::CONNECTION, 'initialized' => $initialized, 'error' => null];
    }

    /**
     * @return array{feature: string, connection: string, initialized: null, error: string}
     */
    private function unknownTable(string $feature, string $connection, string $error): array
    {
        return ['feature' => $feature, 'connection' => $connection, 'initialized' => null, 'error' => $error];
    }
}
