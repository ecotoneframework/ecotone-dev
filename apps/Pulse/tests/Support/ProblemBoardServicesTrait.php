<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

trait ProblemBoardServicesTrait
{
    use PulseServicesTrait;

    /**
     * @var array<string, FlowTestSupport>
     */
    private array $boardServices = [];

    /**
     * @var array<string, object>
     */
    private array $boardState = [];

    /**
     * @param array<string, list<string>|null> $services service name => tenants advertised, or null for a single-tenant service
     */
    private function boardWith(array $services, array $environment = []): KernelBrowser
    {
        $this->configurePulseWithServices(...array_keys($services));
        if ($environment !== []) {
            $this->configurePulse([
                'PULSE_CHANNEL' => $this->pulseChannel,
                'PULSE_SERVICES' => $_SERVER['PULSE_SERVICES'],
                ...$environment,
            ]);
        }
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());
        foreach ($services as $name => $tenants) {
            $this->boardState[$name] = $state = new class ($name, $tenants) {
                public string $version = '2.0.0';

                public array $channels = [];

                public array $deadLetters = ['total' => 0, 'groups' => [], 'recent' => []];

                public array $projections = [];

                public array $trackings = [];

                public array $tenantSections = [];

                public array $setup = [];

                public array $sectionErrors = [];

                public string $framework = 'symfony';

                public function __construct(public string $name, public ?array $tenants)
                {
                }

                #[Distributed]
                #[CommandHandler('ecotone.pulse.trackings.list', endpointId: 'board.trackings')]
                public function trackings(string $request, #[Header('ecotone_pulse_query_id')] string $queryId): string
                {
                    return json_encode(['queryId' => $queryId, 'cap' => 100, 'truncated' => false, 'trackings' => $this->trackings], JSON_THROW_ON_ERROR);
                }

                #[Distributed]
                #[CommandHandler('ecotone.pulse.snapshot', endpointId: 'board.snapshot')]
                public function snapshot(string $request, #[Headers] array $headers): string
                {
                    $base = ['schemaVersion' => 1, 'serviceName' => $this->name, 'takenAt' => gmdate('c')];
                    $tenant = $headers['tenant'] ?? null;
                    if ($this->tenants !== null && $tenant === null) {
                        return json_encode($base + [
                            'overview' => ['ecotoneVersion' => $this->version, 'phpVersion' => PHP_VERSION, 'framework' => $this->framework, 'licence' => ['enterprise' => true, 'expiresAt' => null]],
                            'flowGraph' => ['nodes' => [], 'edges' => []],
                            'topology' => ['serviceMaps' => []],
                            'channels' => array_map(static fn (string $name): array => ['name' => $name, 'kind' => 'async', 'ready' => null, 'delayed' => null, 'inFlight' => null, 'consumers' => null, 'probeError' => null], array_values(array_unique(array_merge(...array_map(static fn (array $section): array => array_column($section['channels'] ?? [], 'name'), array_values($this->tenantSections) ?: [[]]))))),
                            'deadLetter' => ['enabled' => true],
                            'tenancy' => ['header' => 'tenant', 'tenants' => $this->tenants],
                        ] + ($this->setup === [] ? [] : ['setup' => $this->setup]), JSON_THROW_ON_ERROR);
                    }

                    $sections = $tenant === null ? ['channels' => $this->channels, 'deadLetters' => $this->deadLetters, 'projections' => $this->projections] : ['tenant' => $tenant, ...($this->tenantSections[$tenant] ?? [])];

                    return json_encode($base + $sections + ($tenant === null && $this->setup !== [] ? ['setup' => $this->setup] : []) + ($this->sectionErrors === [] ? [] : ['sectionErrors' => $this->sectionErrors]) + ($tenant === null ? [
                        'overview' => ['ecotoneVersion' => $this->version, 'phpVersion' => PHP_VERSION, 'framework' => $this->framework, 'licence' => ['enterprise' => true, 'expiresAt' => null]],
                        'flowGraph' => ['nodes' => [], 'edges' => []],
                        'topology' => ['serviceMaps' => []],
                        'deadLetter' => ['enabled' => true],
                    ] : []), JSON_THROW_ON_ERROR);
                }
            };
            $this->boardServices[$name] = $this->serviceApplication($name, [$state::class], [$state], false);
        }

        return $client;
    }

    private function settleBoard(KernelBrowser $client): void
    {
        foreach ([1, 2] as $round) {
            $client->request('POST', '/refresh');
            foreach (array_keys($this->boardServices) as $name) {
                $this->runService($this->boardServices[$name], $name);
            }
            $this->runPulseWorker($client->getKernel());
        }
    }

    private function refreshBoardOnce(KernelBrowser $client): void
    {
        $client->request('POST', '/refresh');
        foreach (array_keys($this->boardServices) as $name) {
            $this->runService($this->boardServices[$name], $name);
        }
        $this->runPulseWorker($client->getKernel());
    }

    private function observeBoard(KernelBrowser $client, string $uri = '/'): Crawler
    {
        $this->settleBoard($client);
        $client->request('GET', $uri);
        foreach (array_keys($this->boardServices) as $name) {
            $this->runService($this->boardServices[$name], $name);
        }
        $this->runPulseWorker($client->getKernel());

        return $client->request('GET', $uri);
    }

    private function channel(string $name, int $ready): array
    {
        return ['name' => $name, 'kind' => 'async', 'ready' => $ready, 'delayed' => 0, 'inFlight' => 0, 'consumers' => null, 'probeError' => null, 'consumerToRun' => $name, 'handlers' => ['OrderPlaced → notifyCustomer'], 'setUp' => null];
    }

    private function failureGroup(string $messageName, int $count, string $failureId = 'failure-1'): array
    {
        return ['failureId' => $failureId, 'messageName' => $messageName, 'exceptionClass' => 'RuntimeException', 'exceptionFile' => 'a.php', 'exceptionLine' => 1, 'exceptionMessage' => 'boom', 'count' => $count, 'firstFailedAt' => gmdate('c'), 'lastFailedAt' => gmdate('c')];
    }

    private function projectionReport(string $name, int $eventsBehind = 0, string $digest = 'digest', ?string $asyncChannel = 'projection_channel'): array
    {
        return ['name' => $name, 'mode' => 'asynchronous', 'partitioned' => false, 'asyncChannel' => $asyncChannel, 'rebuildAsyncChannel' => 'rebuild_channel', 'initialization' => 'initialized', 'positionDigest' => $digest, 'eventsBehind' => ['events' => $eventsBehind, 'atLeast' => false], 'partitions' => null, 'unavailable' => null];
    }

    private function tracking(string $projection, string $state, ?int $elapsedMs = null): array
    {
        return ['tracingId' => 'trace-' . $projection, 'category' => 'ecotone-pulse', 'type' => 'projection-rebuild', 'reference' => $projection, 'tenant' => null, 'startedAt' => gmdate('Y-m-d\TH:i:s.000000+00:00'), 'state' => $state, 'batchesDone' => 0, 'batchCount' => null, ...($state === 'running' ? ['elapsedMs' => $elapsedMs] : ($state === 'done' ? ['durationMs' => 1000] : ['deadLetterMessageId' => 'dead-1']))];
    }

    /**
     * @return list<array{text: string, href: string, severity: string, kind: string}>
     */
    private function problemsOf(Crawler $crawler, string $tile): array
    {
        return $crawler->filter(sprintf('[data-tile="%s"] [data-problem]', $tile))->each(static fn (Crawler $problem): array => [
            'text' => trim($problem->filter('a')->text()),
            'href' => $problem->filter('a')->attr('href'),
            'severity' => $problem->attr('data-problem-severity'),
            'kind' => $problem->attr('data-problem'),
        ]);
    }
}
