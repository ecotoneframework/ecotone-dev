<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class CollectingPulseTest extends TestCase
{
    public static function implementations(): iterable
    {
        yield 'in_memory' => [false];
        yield 'database' => [true];
    }

    #[DataProvider('implementations')]
    public function test_pulse_lists_open_batches_by_collected_endpoint_and_hides_unknown_size(bool $durable): void
    {
        [$handler, $service, $pulse, $pulseService] = $this->bootstrap($durable);
        $service->sendCommandWithRouting('collect', ['tenant' => 'one']);
        $service->run('batches');
        $batchId = $service->getGateway(GatheredGroups::class)->listOpenBatches('pulse.collect.handler')[0]['groupId'];
        $snapshot = $this->request(service: $service, pulseService: $pulseService, pulse: $pulse, routingKey: 'ecotone.pulse.snapshot', payload: []);

        self::assertArrayHasKey('gathering', $snapshot);
        $batches = array_values(array_filter($snapshot['gathering']['batches'], static fn (array $batch): bool => $batch['groupId'] === $batchId));
        self::assertCount(1, $batches);
        self::assertSame('pulse.collect.handler', $batches[0]['endpointId']);
        self::assertSame('one', $batches[0]['groupKey']);
        self::assertNull($batches[0]['maxSize']);
        self::assertSame('open', $batches[0]['state']);
    }

    #[DataProvider('implementations')]
    public function test_pulse_requests_batch_release_on_its_asynchronous_channel(bool $durable): void
    {
        [$handler, $service, $pulse, $pulseService] = $this->bootstrap($durable);
        $service->sendCommandWithRouting('collect', ['tenant' => 'one']);
        $service->run('batches');
        $batchId = $service->getGateway(GatheredGroups::class)->listOpenBatches('pulse.collect.handler')[0]['groupId'];
        $reply = $this->request(service: $service, pulseService: $pulseService, pulse: $pulse, routingKey: 'ecotone.pulse.gathering.release', payload: ['groupId' => $batchId]);
        self::assertSame(['groupId' => $batchId, 'status' => 'release requested'], $reply['gatheringAction']);
        self::assertSame([], $handler->batches);
        $service->run('batches');
        self::assertCount(1, $handler->batches);
        self::assertSame([['tenant' => 'one']], array_column($handler->batches[0]->messages, 'payload'));
        self::assertSame([], $service->getGateway(GatheredGroups::class)->listOpenBatches('pulse.collect.handler'));
    }

    #[DataProvider('implementations')]
    public function test_pulse_discards_a_batch_without_invoking_its_handler(bool $durable): void
    {
        [$handler, $service, $pulse, $pulseService] = $this->bootstrap($durable);
        $service->sendCommandWithRouting('collect', ['tenant' => 'one']);
        $service->run('batches');
        $batchId = $service->getGateway(GatheredGroups::class)->listOpenBatches('pulse.collect.handler')[0]['groupId'];
        $reply = $this->request(service: $service, pulseService: $pulseService, pulse: $pulse, routingKey: 'ecotone.pulse.gathering.discard', payload: ['groupId' => $batchId]);
        self::assertSame(['groupId' => $batchId, 'status' => 'discarded'], $reply['gatheringAction']);
        $service->advanceTimeBy(new TimeSpan(seconds: 10));
        $service->run('batches');
        self::assertSame([], $handler->batches);
        self::assertSame([], $service->getGateway(GatheredGroups::class)->listOpenBatches('pulse.collect.handler'));
    }

    private function bootstrap(bool $durable): array
    {
        $handler = new class () {
            public array $batches = [];

            #[Collected(maxWait: new TimeSpan(seconds: 10), groupBy: 'payload["tenant"]')]
            #[Asynchronous('batches')]
            #[CommandHandler('collect', endpointId: 'pulse.collect.handler')]
            public function collect(CollectedMessages $batch): void
            {
                $this->batches[] = $batch;
            }
        };
        $pulse = new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
            }
        };
        $channels = [SimpleMessageChannelBuilder::createQueueChannel('collecting_rpc'), SimpleMessageChannelBuilder::createQueueChannel('collecting_pulse_rpc')];
        $service = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                ...$channels,
                SimpleMessageChannelBuilder::createQueueChannel('batches'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'collecting_pulse_rpc'),
                ServiceConfiguration::createWithDefaults()->withServiceName('collecting_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        foreach ($service->getGateway(GatheredGroups::class)->listOpenBatches('pulse.collect.handler') as $batch) {
            $service->getGateway(GatheredGroups::class)->discard($batch['groupId']);
        }
        $pulseService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$pulse::class],
            containerOrAvailableServices: [$pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'collecting_service', channelName: 'collecting_rpc'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        return [$handler, $service, $pulse, $pulseService];
    }

    private function request(FlowTestSupport $service, FlowTestSupport $pulseService, object $pulse, string $routingKey, array $payload): array
    {
        $before = count($pulse->snapshots);
        $pulseService->getDistributedBus()->sendMessage(
            targetServiceName: 'collecting_service',
            routingKey: $routingKey,
            payload: json_encode($payload, JSON_THROW_ON_ERROR),
            sourceMediaType: MediaType::APPLICATION_JSON,
            replyRoutingKey: 'ecotone.pulse.snapshot_received',
        );
        $service->run('collecting_rpc', ExecutionPollingMetadata::createWithTestingSetup());
        $pulseService->run('collecting_pulse_rpc', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertCount($before + 1, $pulse->snapshots);
        return $pulse->snapshots[$before];
    }
}
