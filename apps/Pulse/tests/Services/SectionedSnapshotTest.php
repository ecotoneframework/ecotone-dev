<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class SectionedSnapshotTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    private const HELD = ['overview' => 'digest-of-overview', 'flowGraph' => 'digest-of-flow-graph', 'topology' => 'digest-of-topology', 'deadLetters' => 'digest-of-dead-letters'];

    public function test_the_first_request_names_no_sections_and_a_later_one_names_the_digests_pulse_holds(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        [$orders, $handler] = $this->scriptedService('orders', [$this->everySection(), $this->nothingChanged()]);

        $this->tickAnsweredBy($client, $orders);
        $this->advanceTimeBy(20);
        $this->tickAnsweredBy($client, $orders);

        self::assertEquals([[], self::HELD], array_map(static fn (string $request): array => json_decode($request, true, flags: JSON_THROW_ON_ERROR)['known'], $handler->requests));
    }

    public function test_sections_the_service_reports_unchanged_keep_what_pulse_holds(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        [$orders] = $this->scriptedService('orders', [$this->everySection(), $this->nothingChanged()]);

        $this->tickAnsweredBy($client, $orders);
        $this->advanceTimeBy(20);
        $this->tickAnsweredBy($client, $orders);

        $crawler = $client->request('GET', '/services');
        self::assertSame('connected', $this->serviceStatusOf($crawler, 'orders'));
        self::assertSame('0 s', $crawler->filter('[data-service="orders"] [data-age]')->text());
        self::assertStringContainsString('7.7.7', $crawler->filter('[data-service="orders"]')->text());
        self::assertSame('1', $crawler->filter('[data-summary="dead-letters"] b')->text());
    }

    public function test_a_partial_reply_replaces_only_the_sections_it_carries(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        [$orders] = $this->scriptedService('orders', [$this->everySection(), [
            ...$this->envelope(),
            'deadLetters' => ['total' => 0, 'groups' => [], 'recent' => []],
            'digests' => ['deadLetters' => 'digest-of-no-dead-letters'],
            'partial' => true,
        ]]);

        $this->tickAnsweredBy($client, $orders);
        $this->advanceTimeBy(20);
        $this->tickAnsweredBy($client, $orders);

        $crawler = $client->request('GET', '/services');
        self::assertStringContainsString('7.7.7', $crawler->filter('[data-service="orders"]')->text());
        self::assertSame('0', $crawler->filter('[data-summary="dead-letters"] b')->text());
    }

    public function test_a_full_reply_drops_a_section_the_service_no_longer_reports(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        [$orders] = $this->scriptedService('orders', [$this->everySection(), [
            ...$this->envelope(),
            'digests' => array_diff_key(self::HELD, ['deadLetters' => true]),
        ]]);

        $this->tickAnsweredBy($client, $orders);
        $this->advanceTimeBy(20);
        $this->tickAnsweredBy($client, $orders);

        $crawler = $client->request('GET', '/services');
        self::assertStringContainsString('7.7.7', $crawler->filter('[data-service="orders"]')->text());
        self::assertSame('0', $crawler->filter('[data-summary="dead-letters"] b')->text());
    }

    public function test_a_section_that_failed_on_a_later_reply_is_reported_as_failed_instead_of_kept(): void
    {
        $this->configurePulseWithServices('orders');
        $client = $this->pulseClient();
        [$orders] = $this->scriptedService('orders', [$this->everySection(), [
            ...$this->envelope(),
            'sectionErrors' => ['deadLetters' => 'relation "ecotone_error_messages" does not exist'],
            'digests' => array_diff_key(self::HELD, ['deadLetters' => true]),
        ]]);

        $this->tickAnsweredBy($client, $orders);
        $this->advanceTimeBy(20);
        $this->tickAnsweredBy($client, $orders);

        self::assertSame('0', $client->request('GET', '/services')->filter('[data-summary="dead-letters"] b')->text());
        self::assertStringContainsString('relation "ecotone_error_messages" does not exist', $client->request('GET', '/services/orders')->text());
    }

    private function everySection(): array
    {
        return [
            ...$this->envelope(),
            'overview' => ['ecotoneVersion' => '7.7.7', 'phpVersion' => '8.5.3', 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []],
            'topology' => ['serviceMaps' => []],
            'deadLetters' => ['total' => 1, 'groups' => [['failureId' => 'f-1', 'messageName' => 'order.place', 'exceptionClass' => 'RuntimeException', 'exceptionFile' => 'handler.php', 'exceptionLine' => 1, 'exceptionMessage' => 'boom', 'count' => 1, 'firstFailedAt' => '2026-10-20T11:00:00+00:00', 'lastFailedAt' => '2026-10-20T11:00:00+00:00']], 'recent' => []],
            'digests' => self::HELD,
        ];
    }

    private function nothingChanged(): array
    {
        return [...$this->envelope(), 'digests' => self::HELD];
    }

    private function envelope(): array
    {
        return ['schemaVersion' => 1, 'serviceName' => 'orders', 'takenAt' => '2026-10-20T12:00:00+00:00'];
    }

    private function scriptedService(string $service, array $replies): array
    {
        $handler = new class (array_map(static fn (array $reply): string => json_encode($reply, JSON_THROW_ON_ERROR), $replies)) {
            public array $requests = [];

            public function __construct(private array $replies)
            {
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.snapshot')]
            public function snapshot(string $request): string
            {
                $this->requests[] = $request;

                return $this->replies[count($this->requests) - 1];
            }
        };

        return [$this->serviceApplication($service, [$handler::class], [$handler], false), $handler];
    }

    private function tickAnsweredBy(KernelBrowser $client, FlowTestSupport $service): void
    {
        $command = new CommandTester((new Application($client->getKernel()))->find('pulse:refresh'));
        $command->execute(['--ticks' => 1]);
        $command->assertCommandIsSuccessful();
        $this->runService($service, 'orders');
        $this->runPulseWorker($client->getKernel());
    }

    private function pulseClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        return $client;
    }
}
