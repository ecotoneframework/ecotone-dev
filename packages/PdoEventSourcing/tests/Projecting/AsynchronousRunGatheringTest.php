<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\GatheredReleaseReason;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\PartitionAggregateId;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionBackfill;
use Ecotone\Api\Projecting\ProjectionDelete;
use Ecotone\Api\Projecting\ProjectionExecution;
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Api\Projecting\ProjectionReset;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class AsynchronousRunGatheringTest extends ProjectingTestCase
{
    public function test_asynchronous_rebuild_completes_once_when_every_batch_was_executed(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $ecotone = $this->bootstrapWithAccounts([$projection], 5);

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'account_names']);

        $open = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild');
        self::assertCount(1, $open);
        self::assertSame([3, 0, [1, 2, 3]], [$open[0]['expectedSize'], $open[0]['recorded'], $open[0]['missingPartNumbers']]);

        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild'));
        $closed = $ecotone->getGateway(GatheredGroups::class)->listClosedGroups('account_names.rebuild');
        self::assertCount(1, $closed);
        self::assertSame([3, 3, [], GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR->value], [$closed[0]['expectedSize'], $closed[0]['recorded'], $closed[0]['missingPartNumbers'], $closed[0]['releaseReason']]);
        self::assertSame(['1' => 'Account 1', '2' => 'Account 2', '3' => 'Account 3', '4' => 'Account 4', '5' => 'Account 5'], $projection->sortedNames());
        self::assertSame(5, $projection->resets);
    }

    public function test_asynchronous_backfill_completes_once_when_every_batch_was_executed(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionBackfill(backfillPartitionBatchSize: 2, asyncChannelName: 'backfill_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $ecotone = $this->bootstrapWithAccounts([$projection], 3);
        $ecotone->deleteProjection('account_names');
        $ecotone->initializeProjection('account_names');

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => 'account_names']);

        self::assertSame([[2, 0, [1, 2]]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded'], $group['missingPartNumbers']], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.backfill')));

        $ecotone->run('backfill_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.backfill'));
        self::assertSame([[2, 2, GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR->value]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded'], $group['releaseReason']], $ecotone->getGateway(GatheredGroups::class)->listClosedGroups('account_names.backfill')));
        self::assertSame(['1' => 'Account 1', '2' => 'Account 2', '3' => 'Account 3'], $projection->sortedNames());
    }

    public function test_empty_projection_rebuild_completes_once_its_completion_is_consumed(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $ecotone = $this->bootstrapWithAccounts([$projection], 0);

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'account_names']);

        self::assertSame([[0, 0, []]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded'], $group['missingPartNumbers']], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild')));

        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild'));
        self::assertSame([[0, GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR->value]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['releaseReason']], $ecotone->getGateway(GatheredGroups::class)->listClosedGroups('account_names.rebuild')));
    }

    public function test_rebuild_of_a_projection_with_an_open_run_opens_its_own_group(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $ecotone = $this->bootstrapWithAccounts([$projection], 3);

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'account_names']);
        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'account_names']);

        $open = $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild');
        self::assertCount(2, $open);
        self::assertNotSame($open[0]['groupId'], $open[1]['groupId']);

        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild'));
        self::assertSame([[2, 2], [2, 2]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded']], $ecotone->getGateway(GatheredGroups::class)->listClosedGroups('account_names.rebuild')));
        self::assertSame(['1' => 'Account 1', '2' => 'Account 2', '3' => 'Account 3'], $projection->sortedNames());
    }

    public function test_synchronous_rebuild_is_unchanged(): void
    {
        $synchronous = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $asynchronous = new #[Projection('other_account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $ecotone = $this->bootstrapWithAccounts([$synchronous, $asynchronous], 3);

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'account_names']);

        self::assertSame(['1' => 'Account 1', '2' => 'Account 2', '3' => 'Account 3'], $synchronous->sortedNames());
        self::assertSame(3, $synchronous->resets);
        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('other_account_names.rebuild'));
        $this->expectExceptionMessage("No gathered or collected handler has endpointId 'account_names.rebuild'");
        $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild');
    }

    public function test_completion_can_be_consumed_from_a_separate_channel(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_channel', completionChannelName: 'completion_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $ecotone = $this->bootstrapWithAccounts([$projection], 3);

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'account_names']);
        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['1' => 'Account 1', '2' => 'Account 2', '3' => 'Account 3'], $projection->sortedNames());
        self::assertSame([[2, 0]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded']], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild')));

        $ecotone->run('completion_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild'));
        self::assertSame([[2, 2]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded']], $ecotone->getGateway(GatheredGroups::class)->listClosedGroups('account_names.rebuild')));
    }

    public function test_backfill_completion_can_be_consumed_from_a_separate_channel(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionBackfill(backfillPartitionBatchSize: 2, asyncChannelName: 'backfill_channel', completionChannelName: 'completion_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $ecotone = $this->bootstrapWithAccounts([$projection], 3);

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => 'account_names']);
        $ecotone->run('backfill_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame([[2, 0]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded']], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.backfill')));

        $ecotone->run('completion_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame([[2, 2]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded']], $ecotone->getGateway(GatheredGroups::class)->listClosedGroups('account_names.backfill')));
    }

    public function test_rebuild_completion_channel_without_asynchronous_rebuild_is_refused_at_bootstrap(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, completionChannelName: 'completion_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };

        $this->expectExceptionMessage('#[ProjectionRebuild] names completionChannelName \'completion_channel\' but no asyncChannelName, so the rebuild runs in the calling process and there is no asynchronous completion to consume. Add asyncChannelName to run the rebuild asynchronously, or remove completionChannelName.');

        $this->bootstrapWithAccounts([$projection], 0);
    }

    public function test_backfill_completion_channel_without_asynchronous_backfill_is_refused_at_bootstrap(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionBackfill(completionChannelName: 'completion_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };

        $this->expectExceptionMessage('#[ProjectionBackfill] names completionChannelName \'completion_channel\' but no asyncChannelName, so the backfill runs in the calling process and there is no asynchronous completion to consume. Add asyncChannelName to run the backfill asynchronously, or remove completionChannelName.');

        $this->bootstrapWithAccounts([$projection], 0);
    }

    public function test_application_endpoint_id_equal_to_a_run_endpoint_id_is_refused_at_bootstrap(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $handler = new class () {
            #[CommandHandler('account.audit', endpointId: 'account_names.rebuild')]
            public function audit(): void
            {
            }
        };

        $this->expectExceptionMessageMatches("/^Projection 'account_names' tracks its asynchronous rebuild under endpointId 'account_names.rebuild', which the handler .+::audit also uses\\. Give that handler another endpointId, rename the projection, or remove asyncChannelName from #\\[ProjectionRebuild\\]\\.$/");

        $this->bootstrapWithAccounts([$projection, $handler], 0);
    }

    public function test_failed_batch_stays_missing_until_it_is_replayed_from_the_dead_letter(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $ecotone = $this->bootstrapWithAccounts([$projection], 5, [ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter')]);
        $projection->failOn = '3';

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'account_names']);
        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        $ecotone->advanceTimeBy(new TimeSpan(days: 30));
        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        self::assertSame([[3, 2, [2]]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded'], $group['missingPartNumbers']], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild')));
        self::assertSame(1, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_replayed_batch_completes_the_rebuild(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $ecotone = $this->bootstrapWithAccounts([$projection], 5, [ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter')]);
        $projection->failOn = '3';
        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'account_names']);
        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        $projection->failOn = null;
        $ecotone->getGateway(DeadLetterGateway::class)->replayAll();
        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild'));
        self::assertSame([[3, 3, GatheredReleaseReason::ALL_PARTS_ACCOUNTED_FOR->value]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded'], $group['releaseReason']], $ecotone->getGateway(GatheredGroups::class)->listClosedGroups('account_names.rebuild')));
        self::assertSame(['1' => 'Account 1', '2' => 'Account 2', '3' => 'Account 3', '4' => 'Account 4', '5' => 'Account 5'], $projection->sortedNames());
    }

    public function test_batch_failing_after_its_first_partition_is_not_counted_as_done(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $ecotone = $this->bootstrapWithAccounts([$projection], 2, [ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter')]);
        $projection->failOn = '2';

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'account_names']);
        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        self::assertSame([[1, 0, [1]]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded'], $group['missingPartNumbers']], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild')));
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function test_stop_signal_during_a_rebuild_batch_lets_the_batch_finish_and_leaves_the_next_batch_on_the_channel(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $ecotone = $this->bootstrapWithAccounts([$projection], 3);
        $projection->stopOn = '1';

        $ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'account_names']);
        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(2, $projection->resets);
        self::assertSame(['1' => 'Account 1', '2' => 'Account 2', '3' => 'Account 3'], $projection->sortedNames());
        self::assertSame([[2, 0, [1, 2]]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded'], $group['missingPartNumbers']], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild')));

        $projection->stopOn = null;
        $ecotone->run('rebuild_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(3, $projection->resets);
        self::assertSame([[2, 2]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded']], $ecotone->getGateway(GatheredGroups::class)->listClosedGroups('account_names.rebuild')));
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function test_stop_signal_during_a_backfill_batch_lets_each_partition_of_the_batch_finish(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionExecution(eventLoadingBatchSize: 1), ProjectionBackfill(backfillPartitionBatchSize: 2, asyncChannelName: 'backfill_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $ecotone = $this->bootstrapWithAccounts([$projection], 3);
        foreach (['1', '2', '3'] as $account) {
            $ecotone->sendCommand(new RenameGatheredRunAccount($account, "Renamed {$account}"));
        }
        $ecotone->deleteProjection('account_names');
        $ecotone->initializeProjection('account_names');
        $projection->stopOn = '1';

        $ecotone->runConsoleCommand('ecotone:projection:backfill', ['name' => 'account_names']);
        $ecotone->run('backfill_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['1' => 'Renamed 1', '2' => 'Renamed 2'], $projection->sortedNames());
        self::assertSame([[2, 0, [1, 2]]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded'], $group['missingPartNumbers']], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('account_names.backfill')));
    }

    public function test_run_group_survives_application_restart(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'durable_rebuild_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };
        $this->bootstrapWithAccounts([$projection], 3, [DbalBackedMessageChannelBuilder::create('durable_rebuild_channel')])
            ->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'account_names']);

        $restarted = $this->bootstrapWithAccounts([$projection], 0, [DbalBackedMessageChannelBuilder::create('durable_rebuild_channel')]);
        self::assertSame([[2, 0]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded']], $restarted->getGateway(GatheredGroups::class)->listOpenGroups('account_names.rebuild')));
        $restarted->run('durable_rebuild_channel', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame([[2, 2]], array_map(static fn (array $group): array => [$group['expectedSize'], $group['recorded']], $restarted->getGateway(GatheredGroups::class)->listClosedGroups('account_names.rebuild')));
    }

    public function test_asynchronous_rebuild_with_dbal_is_accepted_without_test_configuration(): void
    {
        $projection = new #[Projection('account_names'), Partitioned, ProjectionRebuild(partitionBatchSize: 2, asyncChannelName: 'rebuild_channel'), FromAggregateStream(GatheredRunAccount::class)] class () extends GatheredRunAccountNames {
        };

        $ecotone = EcotoneLite::bootstrap(
            classesToResolve: [$projection::class, GatheredRunAccount::class],
            containerOrAvailableServices: [$projection, self::getConnectionFactory()],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('rebuild_channel')],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        self::assertInstanceOf(GatheredGroups::class, $ecotone->getServiceFromContainer(GatheredGroups::class));
    }

    private function bootstrapWithAccounts(array $projections, int $accounts, array $extensionObjects = []): FlowTestSupport
    {
        $converter = new class () {
            #[Converter]
            public function fromOpened(GatheredRunAccountOpened $event): array
            {
                return ['accountId' => $event->accountId, 'name' => $event->name];
            }

            #[Converter]
            public function toOpened(array $event): GatheredRunAccountOpened
            {
                return new GatheredRunAccountOpened($event['accountId'], $event['name']);
            }

            #[Converter]
            public function fromRenamed(GatheredRunAccountRenamed $event): array
            {
                return ['accountId' => $event->accountId, 'name' => $event->name];
            }

            #[Converter]
            public function toRenamed(array $event): GatheredRunAccountRenamed
            {
                return new GatheredRunAccountRenamed($event['accountId'], $event['name']);
            }
        };
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [...array_map(static fn (object $projection): string => $projection::class, $projections), $converter::class, GatheredRunAccount::class],
            containerOrAvailableServices: [...$projections, $converter, self::getConnectionFactory()],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('rebuild_channel'), SimpleMessageChannelBuilder::createQueueChannel('backfill_channel'), SimpleMessageChannelBuilder::createQueueChannel('completion_channel'), DbalConfiguration::createWithDefaults(), ...$extensionObjects],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            initialTime: new DateTimeImmutable('2026-11-14 12:00:00 UTC'),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        for ($account = 1; $account <= $accounts; $account++) {
            $ecotone->sendCommand(new OpenGatheredRunAccount((string) $account, "Account {$account}"));
        }
        foreach ($projections as $projection) {
            $projection->resets = 0;
        }

        return $ecotone;
    }
}

abstract class GatheredRunAccountNames
{
    public array $names = [];
    public int $resets = 0;
    public ?string $failOn = null;
    public ?string $stopOn = null;

    #[EventHandler]
    public function whenOpened(GatheredRunAccountOpened $event): void
    {
        if ($event->accountId === $this->failOn) {
            throw new RuntimeException("Account {$event->accountId} cannot be projected");
        }
        if ($event->accountId === $this->stopOn) {
            posix_kill(posix_getpid(), SIGTERM);
        }
        $this->names[$event->accountId] = $event->name;
    }

    #[EventHandler]
    public function whenRenamed(GatheredRunAccountRenamed $event): void
    {
        $this->names[$event->accountId] = $event->name;
    }

    #[ProjectionReset]
    public function reset(#[PartitionAggregateId] string $accountId): void
    {
        unset($this->names[$accountId]);
        $this->resets++;
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->names = [];
    }

    public function sortedNames(): array
    {
        $names = $this->names;
        ksort($names);

        return $names;
    }
}

final class OpenGatheredRunAccount
{
    public function __construct(public readonly string $accountId, public readonly string $name)
    {
    }
}

final class RenameGatheredRunAccount
{
    public function __construct(public readonly string $accountId, public readonly string $name)
    {
    }
}

final class GatheredRunAccountRenamed
{
    public function __construct(public readonly string $accountId, public readonly string $name)
    {
    }
}

final class GatheredRunAccountOpened
{
    public function __construct(public readonly string $accountId, public readonly string $name)
    {
    }
}

#[EventSourcingAggregate]
final class GatheredRunAccount
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $accountId;

    #[CommandHandler]
    public static function open(OpenGatheredRunAccount $command): array
    {
        return [new GatheredRunAccountOpened($command->accountId, $command->name)];
    }

    #[CommandHandler]
    public function rename(RenameGatheredRunAccount $command): array
    {
        return [new GatheredRunAccountRenamed($command->accountId, $command->name)];
    }

    #[Apply]
    public function applyOpened(GatheredRunAccountOpened $event): void
    {
        $this->accountId = $event->accountId;
    }
}
