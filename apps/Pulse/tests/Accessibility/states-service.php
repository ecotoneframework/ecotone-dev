<?php

declare(strict_types=1);

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;

require __DIR__ . '/../../vendor/autoload.php';

final class StatesService
{
    #[Distributed]
    #[CommandHandler('ecotone.pulse.trackings.list', endpointId: 'states.trackings')]
    public function trackings(string $request, #[Header('ecotone_pulse_query_id')] string $queryId): string
    {
        return json_encode(['queryId' => $queryId, 'cap' => 100, 'truncated' => false, 'trackings' => []], JSON_THROW_ON_ERROR);
    }

    #[Distributed]
    #[CommandHandler('ecotone.pulse.snapshot', endpointId: 'states.snapshot')]
    public function snapshot(string $request): string
    {
        $channel = static fn (string $name, int $ready, ?bool $setUp, ?string $probeError = null): array => ['name' => $name, 'kind' => 'async', 'ready' => $ready, 'delayed' => 0, 'inFlight' => 0, 'consumers' => null, 'probeError' => $probeError, 'consumerToRun' => $name, 'handlers' => ['OrderPlaced → notifyCustomer'], 'setUp' => $setUp];
        $projection = static fn (string $name, string $initialization, bool $manualKickOff): array => ['name' => $name, 'mode' => 'asynchronous', 'partitioned' => false, 'asyncChannel' => 'projection_channel', 'rebuildAsyncChannel' => 'rebuild_channel', 'backfillAsyncChannel' => null, 'initialization' => $initialization, 'manualKickOff' => $manualKickOff, 'positionDigest' => 'digest', 'eventsBehind' => ['events' => 0, 'atLeast' => false], 'partitions' => null, 'unavailable' => null];
        $table = static fn (string $feature, string $connection, ?bool $initialized, ?string $error = null): array => ['feature' => $feature, 'connection' => $connection, 'initialized' => $initialized, 'error' => $error];

        return json_encode([
            'schemaVersion' => 1,
            'serviceName' => 'states',
            'takenAt' => gmdate('c'),
            'overview' => ['ecotoneVersion' => '2.0.0', 'phpVersion' => PHP_VERSION, 'framework' => 'symfony', 'licence' => ['enterprise' => true, 'expiresAt' => null]],
            'flowGraph' => ['nodes' => [], 'edges' => []],
            'topology' => ['serviceMaps' => []],
            'deadLetter' => ['enabled' => true],
            'channels' => [$channel('stuck', 12, true), $channel('invoices', 0, false), $channel('emails', 0, null, 'connection refused')],
            'deadLetters' => ['total' => 0, 'groups' => [], 'recent' => []],
            'projections' => [$projection('not_initialized_projection', 'uninitialized', true), $projection('prepared_projection', 'prepared', true), $projection('waiting_projection', 'uninitialized', false)],
            'setup' => ['tables' => [
                $table('message_queue', 'Ecotone\Api\Dbal\DbalConnectionFactory', false),
                $table('dead_letter', 'Ecotone\Api\Dbal\DbalConnectionFactory', true),
                $table('document_store', 'reporting', null, 'connection refused'),
                $table('deduplication', 'reporting', null, 'not checked within the budget'),
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}

$dsn = getenv('PULSE_TRANSPORT_DSN');
$application = EcotoneLite::bootstrapFlowTesting(
    classesToResolve: [StatesService::class],
    containerOrAvailableServices: [StatesService::class => new StatesService(), DbalConnectionFactory::class => new DbalConnectionFactory($dsn)],
    extensionObjects: [
        DbalBackedMessageChannelBuilder::create('states')->withReceiveTimeout(1),
        DbalBackedMessageChannelBuilder::create('ecotone_pulse')->withReceiveTimeout(1),
        DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'ecotone_pulse'),
        ServiceConfiguration::createWithDefaults()->withServiceName('states'),
    ],
    enabledModules: [ModulePackageList::CORE_PACKAGE, ModulePackageList::DBAL_PACKAGE],
    licenceKey: getenv('ECOTONE_LICENCE_KEY'),
);

$application->run('states', ExecutionPollingMetadata::createWithDefaults()->withExecutionTimeLimitInMilliseconds((int) (getenv('SMOKE_SECONDS') ?: 1500) * 1000));
