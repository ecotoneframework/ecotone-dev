<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class GatheredPartOutcomeRecordingTest extends DbalMessagingTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function stores(): iterable
    {
        yield 'in memory group store' => [false];
        yield 'DBAL group store' => [true];
    }

    #[DataProvider('stores')]
    public function test_parts_arriving_in_order_are_recorded_as_stored_while_the_group_waits_and_then_released(bool $dbal): void
    {
        $ecotone = $this->application($dbal, duplicated: false);

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->run('workers')->run('summaries');

        self::assertSame(['part stored, 1 of 2 in, group waits', 'part stored, 2 of 2 in, group released'], $this->outcomesOfParts($ecotone));
    }

    #[DataProvider('stores')]
    public function test_a_part_delivered_twice_is_recorded_as_a_duplicate_while_the_group_is_open_and_after_it_was_released(bool $dbal): void
    {
        $ecotone = $this->application($dbal, duplicated: true);

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->run('workers')->run('summaries');

        self::assertSame(['part stored, 1 of 2 in, group waits', 'duplicate part ignored', 'part stored, 2 of 2 in, group released', 'duplicate part ignored, group already released'], $this->outcomesOfParts($ecotone));
    }

    #[DataProvider('stores')]
    public function test_a_part_arriving_after_a_requested_release_is_recorded_as_late_for_a_released_group(bool $dbal): void
    {
        $ecotone = $this->application($dbal, duplicated: false);

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->getGateway(GatheredGroups::class)->requestRelease($ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary.handler')[0]['groupId']);
        $ecotone->run('summaries')->run('workers')->run('summaries');

        self::assertSame(['late part ignored, group already released', 'late part ignored, group already released'], $this->outcomesOfParts($ecotone));
    }

    #[DataProvider('stores')]
    public function test_a_part_arriving_after_the_group_was_discarded_is_recorded_as_late_for_a_discarded_group(bool $dbal): void
    {
        $ecotone = $this->application($dbal, duplicated: false);

        $ecotone->sendCommandWithRouting('split', [10, 20]);
        $ecotone->getGateway(GatheredGroups::class)->discard($ecotone->getGateway(GatheredGroups::class)->listOpenGroups('summary.handler')[0]['groupId']);
        $ecotone->run('workers')->run('summaries');

        self::assertSame(['late part ignored, group already discarded', 'late part ignored, group already discarded'], $this->outcomesOfParts($ecotone));
    }

    /**
     * @return list<string>
     */
    private function outcomesOfParts(FlowTestSupport $ecotone): array
    {
        $lines = array_map(trim(...), $ecotone->popExecutionRecording()->lines);

        return array_values(array_map(
            fn (int $position): string => substr($lines[$position + 1], strlen('↳ outcome: ')),
            array_keys(array_filter($lines, fn (string $line): bool => preg_match('/^record part \d of 2 in (InMemory|Dbal)GatheredGroupStore$/', $line) === 1)),
        ));
    }

    private function application(bool $dbal, bool $duplicated): FlowTestSupport
    {
        $handler = $duplicated
            ? new class () {
                #[CommandHandler('split', outputChannelName: 'parts')]
                public function start(array $parts): array
                {
                    return $parts;
                }

                #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
                public function split(array $parts): array
                {
                    return $parts;
                }

                #[Asynchronous('workers')]
                #[InternalHandler('work', endpointId: 'work.handler')]
                public function work(int $part): int
                {
                    return $part;
                }

                #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE - 1, pointcut: AsynchronousRunningEndpoint::class)]
                public function duplicateWorkerDelivery(MethodInvocation $invocation, AsynchronousRunningEndpoint $endpoint): mixed
                {
                    $again = $invocation->cloneCurrentState();
                    $result = $invocation->proceed();
                    if ($endpoint->getEndpointId() === 'workers') {
                        $again->proceed();
                    }

                    return $result;
                }

                #[Gathered]
                #[Asynchronous('summaries')]
                #[InternalHandler('summary', endpointId: 'summary.handler')]
                public function summarize(GatheredParts $parts): void
                {
                }
            }
        : new class () {
            #[CommandHandler('split', outputChannelName: 'parts')]
            public function start(array $parts): array
            {
                return $parts;
            }

            #[Splitter('parts', endpointId: 'split.parts', outputChannelName: 'work', gatherChannelName: 'summary')]
            public function split(array $parts): array
            {
                return $parts;
            }

            #[Asynchronous('workers')]
            #[InternalHandler('work', endpointId: 'work.handler')]
            public function work(int $part): int
            {
                return $part;
            }

            #[Gathered]
            #[Asynchronous('summaries')]
            #[InternalHandler('summary', endpointId: 'summary.handler')]
            public function summarize(GatheredParts $parts): void
            {
            }
        };
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [$handler, ...($dbal ? [DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))] : [])],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('workers'),
                SimpleMessageChannelBuilder::createQueueChannel('summaries'),
                TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework),
                ...($dbal ? [DbalConfiguration::createWithDefaults()->withDeduplication(false)] : []),
            ],
            enabledModules: [ModulePackageList::TRACING_PACKAGE, ...($dbal ? [ModulePackageList::DBAL_PACKAGE] : [])],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
    }
}
