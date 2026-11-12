<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;
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
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Messaging\GatheredReleaseReason;
use Ecotone\Api\Pulse\PulseConfiguration;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class GatheringPulseTest extends TestCase
{
    private const ENTERPRISE_LICENCE = 'eyJzaWduYXR1cmUiOiJxRmtrSDdCQkxzdUlTRzExZmVXeHVKVTY3bGZScmFSTFBpb0VUaGdoZTAyYWRjajlVRkNWWXhvaGl4YXhoaEFlNzU5aExOT0R5NXo1T2N2eFppUGQyemRRblYrb1dNZjFwM2lqbFN4cUg1MDhVV1wvOG90TllkM28wMVRZRmdMSjU4RXJzYThLcUJib0Z1MmhaZW9lczQ3elM2VHR5TzFGV2pOeWxlbG1aRWNLUHFydFhzOTNcL1YxY0wwWVg4UFBOZDl6bVRPOWxyc3UzM3F1anBXVlB4eVRMTW4yK3lPc3Jxa2pJZDArSU1LSWl0dG1IZGlBcml6NnZIXC93RnFKTU5Od2JKWnFsUXRNa3pQc3pvRlFseVgyZnNPTG5CTWNCajA5S0VnNjhVcG14OVwvUnBHZXBNV2UzcThYeFZMa3laOEtXdndEN2JBZDZPSXVoejlxRThyamVQVVVsbUxybm0wd3ZFYjV6UVZEbU5OY21uZnNkMEYyazJhSldySzc5VXlEWVY3UFNvVkZiM0diV0VNN2p1STVFV2w5cTVaWXJiOGxFcmxTTmNpYmQwbCttMWkzUWk1TnZhU1RscHBTV1BGS0g4c2x5dDhKRzV4YXZqQlJFU3BJWGVDNE9VREdoZDRJNEhaaEpDXC9Wcm9Nb0FRNzBMaXRrUHcxWkdWQ1dqTURucHdNdWRyWThpMVQwak5MRytXYlpYckpvRHZxd0x4SENKdU9EWnhuYVNISkVVSVFndGViUXZPRURIKzBjN0o0VVJMWkV2NnU2d2tcL1F6TzlPVnVVS1BubDkzK1dVam8xVDZqOEJ0MEtSeHhsMmtBY2pQcE9wWTg5Q25BQlBtckF4THJhYkxXeThpZmxVQ0cwUjNmZzB4UnpiMjlMSENvUW95b2NmRStzYjhFYz0iLCJkYXRhIjp7ImVtYWlsIjoiam9obnlAd3AucGwiLCJleHBpcmVBdCI6IjIwMzItMTAtMTEgMDA6MDA6MDAiLCJpc0VudGVycHJpc2VQbHVzIjpmYWxzZX19';

    private object $handler;
    private object $pulse;
    private FlowTestSupport $service;
    private FlowTestSupport $pulseService;

    public static function implementations(): iterable
    {
        yield 'in-memory' => [false];
        yield 'dbal' => [true];
    }

    #[DataProvider('implementations')]
    public function test_pulse_snapshot_lists_open_and_closed_groups_with_late_arrivals(bool $durable): void
    {
        $this->bootstrap($durable);
        $groups = $this->service->getGateway(GatheredGroups::class);
        $before = $groups->listOpenGroups('pulse.summary');
        $this->service->sendCommandWithRouting('split', [10, 20]);
        $this->service->run('workers', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1));
        $this->service->run('summaries');
        $opened = array_values(array_filter($groups->listOpenGroups('pulse.summary'), static fn (array $group): bool => ! in_array($group['groupId'], array_column($before, 'groupId'), true)));
        $groupId = $opened[0]['groupId'];

        $snapshot = $this->request('ecotone.pulse.snapshot', []);
        self::assertArrayHasKey('gathering', $snapshot);
        $open = array_values(array_filter($snapshot['gathering']['groups'], static fn (array $group): bool => $group['groupId'] === $groupId));
        self::assertCount(1, $open);
        self::assertSame('open', $open[0]['state']);
        self::assertSame([1], $open[0]['accountedPartNumbers']);
        self::assertSame([2], $open[0]['missingPartNumbers']);
        $groups->releaseNow($groupId);
        $this->service->run('summaries');
        $this->service->run('workers');
        $this->service->run('summaries');
        $snapshot = $this->request('ecotone.pulse.snapshot', []);
        $closed = array_values(array_filter($snapshot['gathering']['groups'], static fn (array $group): bool => $group['groupId'] === $groupId));

        self::assertCount(1, $closed);
        self::assertSame('released', $closed[0]['state']);
        self::assertSame(1, $closed[0]['latePartCount']);
        self::assertSame([1], $closed[0]['accountedPartNumbers']);
        self::assertSame([2], $closed[0]['missingPartNumbers']);
    }

    #[DataProvider('implementations')]
    public function test_pulse_release_reports_requested_until_handler_consumes_trigger(bool $durable): void
    {
        $this->bootstrap($durable);
        $groups = $this->service->getGateway(GatheredGroups::class);
        $before = $groups->listOpenGroups('pulse.summary');
        $this->service->sendCommandWithRouting('split', [10, 20]);
        $opened = array_values(array_filter($groups->listOpenGroups('pulse.summary'), static fn (array $group): bool => ! in_array($group['groupId'], array_column($before, 'groupId'), true)));
        $groupId = $opened[0]['groupId'];

        $reply = $this->request('ecotone.pulse.gathering.release', ['groupId' => $groupId]);
        self::assertSame(['groupId' => $groupId, 'status' => 'release requested'], $reply['gatheringAction']);
        self::assertSame([], $this->handler->gathered);
        self::assertContains($groupId, array_column($groups->listOpenGroups('pulse.summary'), 'groupId'));
        $this->request('ecotone.pulse.gathering.release', ['groupId' => $groupId]);
        $this->service->run('summaries');

        self::assertCount(1, $this->handler->gathered);
        self::assertSame([1, 2], $this->handler->gathered[0]->missingPartNumbers);
        self::assertSame(GatheredReleaseReason::RELEASED_BY_HAND, $this->handler->gathered[0]->releaseReason);
    }

    #[DataProvider('implementations')]
    public function test_pulse_discard_closes_the_group_without_invoking_handler(bool $durable): void
    {
        $this->bootstrap($durable);
        $groups = $this->service->getGateway(GatheredGroups::class);
        $before = $groups->listOpenGroups('pulse.summary');
        $this->service->sendCommandWithRouting('split', [10, 20]);
        $opened = array_values(array_filter($groups->listOpenGroups('pulse.summary'), static fn (array $group): bool => ! in_array($group['groupId'], array_column($before, 'groupId'), true)));
        $groupId = $opened[0]['groupId'];

        $reply = $this->request('ecotone.pulse.gathering.discard', ['groupId' => $groupId]);
        self::assertSame(['groupId' => $groupId, 'status' => 'discarded'], $reply['gatheringAction']);
        self::assertNotContains($groupId, array_column($groups->listOpenGroups('pulse.summary'), 'groupId'));
        $this->service->run('workers');
        $this->service->run('summaries');
        $closed = array_values(array_filter($groups->listClosedGroups('pulse.summary'), static fn (array $group): bool => $group['groupId'] === $groupId));

        self::assertSame([], $this->handler->gathered);
        self::assertSame('discarded', $closed[0]['state']);
        self::assertSame(2, $closed[0]['latePartCount']);
    }

    public static function invalidActionBodies(): iterable
    {
        foreach (['release', 'discard'] as $action) {
            foreach (['missing' => '{}', 'list' => '[]', 'empty id' => '{"groupId":""}', 'non-string id' => '{"groupId":7}', 'unknown field' => '{"groupId":"id","all":true}', 'malformed json' => '{'] as $case => $body) {
                yield $action . ': ' . $case => [$action, $body];
            }
        }
    }

    #[DataProvider('invalidActionBodies')]
    public function test_pulse_action_refuses_invalid_group_selection(string $action, string $body): void
    {
        $this->bootstrap(false);

        $reply = $this->request('ecotone.pulse.gathering.' . $action, $body);

        self::assertSame('Pulse gathering actions require a JSON object containing only a non-empty string groupId. Send {"groupId":"id"}, taking id from gathering.groups in the Pulse snapshot.', $reply['message']);
        self::assertSame([], $this->service->getGateway(GatheredGroups::class)->listOpenGroups('pulse.summary'));
        self::assertSame([], $this->handler->gathered);
    }

    public static function actions(): iterable
    {
        yield 'release' => ['release'];
        yield 'discard' => ['discard'];
    }

    #[DataProvider('actions')]
    public function test_unconfigured_pulse_service_cannot_change_a_group(string $action): void
    {
        $this->bootstrap(false, 'intruder');
        $this->service->sendCommandWithRouting('split', [10]);
        $groups = $this->service->getGateway(GatheredGroups::class);
        $groupId = $groups->listOpenGroups('pulse.summary')[0]['groupId'];

        try {
            $this->request('ecotone.pulse.gathering.' . $action, ['groupId' => $groupId]);
            self::fail();
        } catch (Exception $exception) {
            self::assertSame("Service gathering_service was asked for its Pulse snapshot by intruder, but it only answers the configured Pulse service ecotone_pulse. Send the request from the Pulse service, or change the accepted service with PulseConfiguration::createWithDefaults(pulseServiceName: 'intruder') in a #[ServiceContext].", $exception->getMessage());
        }
        self::assertSame($groupId, $groups->listOpenGroups('pulse.summary')[0]['groupId']);
        $this->service->run('summaries');
        self::assertSame([], $this->handler->gathered);
    }

    private function bootstrap(bool $durable, string $pulseServiceName = 'ecotone_pulse'): void
    {
        $this->handler = $handler = new class () {
            public array $gathered = [];

            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'pulse.split', outputChannelName: 'work', gatherChannelName: 'pulse.summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'pulse.work')]
            public function work(int $part): int
            {
                return $part * 2;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('pulse.summary', endpointId: 'pulse.summary.handler')]
            public function summary(GatheredParts $parts): void
            {
                $this->gathered[] = $parts;
            }
        };
        $this->pulse = $pulse = new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
            }
        };
        $channels = [SimpleMessageChannelBuilder::createQueueChannel('gathering_rpc'), SimpleMessageChannelBuilder::createQueueChannel('pulse_rpc')];
        $this->service = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: $durable ? [$handler, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [$handler],
            extensionObjects: [
                ...$channels,
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_rpc'),
                ServiceConfiguration::createWithDefaults()->withServiceName('gathering_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: $durable ? [ModulePackageList::DBAL_PACKAGE] : [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
        $this->pulseService = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$pulse::class],
            containerOrAvailableServices: [$pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'gathering_service', channelName: 'gathering_rpc'),
                ServiceConfiguration::createWithDefaults()->withServiceName($pulseServiceName),
            ],
            enabledModules: [],
            licenceKey: self::ENTERPRISE_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
    }

    private function request(string $routingKey, array|string $payload): array
    {
        $before = count($this->pulse->snapshots);
        $this->pulseService->getDistributedBus()->sendMessage(
            targetServiceName: 'gathering_service',
            routingKey: $routingKey,
            payload: is_array($payload) ? json_encode($payload, JSON_THROW_ON_ERROR) : $payload,
            sourceMediaType: MediaType::APPLICATION_JSON,
            replyRoutingKey: 'ecotone.pulse.snapshot_received',
        );
        $this->service->run('gathering_rpc', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_rpc', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertCount($before + 1, $this->pulse->snapshots);

        return $this->pulse->snapshots[$before];
    }
}
