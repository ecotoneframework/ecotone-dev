<?php

declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionBackfill;
use Ecotone\Api\Projecting\ProjectionRebuild;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
final class AsynchronousRunConfigurationTest extends TestCase
{
    public function test_asynchronous_rebuild_without_durable_storage_is_refused_outside_flow_testing_with_the_ways_out(): void
    {
        $projection = new #[Projection('orders'), FromStream('orders_stream'), ProjectionRebuild(asyncChannelName: 'rebuild_channel')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };

        $this->expectExceptionMessage("Projection 'orders' runs its rebuild asynchronously on 'rebuild_channel', so the run is tracked as a gathering group that must survive restarts and be shared between consumers, which needs durable storage when bootstrapping without test configuration. Install ecotone/dbal and enable its module: add ModulePackageList::DBAL_PACKAGE to EcotoneLite::bootstrap(..., enabledModules: [...]), or 'dbal' to enabledModulePackageNames (the Symfony bundle option, config/ecotone.php key in Laravel, or EcotoneConfig argument in Tempest). Before the first rebuild, run ecotone:migration:database:setup --initialize --feature=gathering, or generate SQL for your migrations with ecotone:migration:database:setup --sql --feature=gathering. Use EcotoneLite::bootstrapFlowTesting() for in-memory flow tests, or remove asyncChannelName from #[ProjectionRebuild] to run the rebuild in the calling process.");

        EcotoneLite::bootstrap([], [$projection], extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('rebuild_channel')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    public function test_asynchronous_backfill_without_durable_storage_is_refused_outside_flow_testing_with_the_ways_out(): void
    {
        $projection = new #[Projection('orders'), FromStream('orders_stream'), ProjectionBackfill(asyncChannelName: 'backfill_channel')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };

        $this->expectExceptionMessage("Projection 'orders' runs its backfill asynchronously on 'backfill_channel'");
        $this->expectExceptionMessage('or remove asyncChannelName from #[ProjectionBackfill] to run the backfill in the calling process.');

        EcotoneLite::bootstrap([], [$projection], extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('backfill_channel')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    public function test_an_unregistered_rebuild_channel_is_refused_naming_the_attribute_and_the_ways_out(): void
    {
        $projection = new #[Projection('orders'), FromStream('orders_stream'), ProjectionRebuild(asyncChannelName: 'rebuild_channel')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };

        $this->expectExceptionMessage("Projection 'orders' runs its rebuild on 'rebuild_channel' (asyncChannelName of #[ProjectionRebuild]), which is not registered as a message channel. Register it from a #[ServiceContext] method, for example DbalBackedMessageChannelBuilder::create('rebuild_channel'), or remove asyncChannelName from #[ProjectionRebuild] to run the rebuild in the calling process.");

        EcotoneLite::bootstrapFlowTesting([], [$projection], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    public function test_an_unregistered_backfill_channel_is_refused_naming_the_attribute_and_the_ways_out(): void
    {
        $projection = new #[Projection('orders'), FromStream('orders_stream'), ProjectionBackfill(asyncChannelName: 'backfill_channel')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };

        $this->expectExceptionMessage("Projection 'orders' runs its backfill on 'backfill_channel' (asyncChannelName of #[ProjectionBackfill]), which is not registered as a message channel.");

        EcotoneLite::bootstrapFlowTesting([], [$projection], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    public function test_an_unregistered_batch_completion_channel_is_refused_naming_the_attribute_and_the_ways_out(): void
    {
        $projection = new #[Projection('orders'), FromStream('orders_stream'), ProjectionRebuild(asyncChannelName: 'rebuild_channel', batchCompletionChannelName: 'batch_completion_channel')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };

        $this->expectExceptionMessage("Projection 'orders' consumes the batch reports of its rebuild on 'batch_completion_channel' (batchCompletionChannelName of #[ProjectionRebuild]), which is not registered as a message channel. Register it from a #[ServiceContext] method, for example DbalBackedMessageChannelBuilder::create('batch_completion_channel'), or remove batchCompletionChannelName to consume them on 'rebuild_channel'.");

        EcotoneLite::bootstrapFlowTesting([], [$projection], extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('rebuild_channel')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    #[DataProvider('projections_run_asynchronously')]
    public function test_consumer_of_the_batch_reports_of_a_projection_run_is_refused_to_consume_on_coroutines_passed_when_running_it(object $projection, string $channel, string $refusal): void
    {
        $ecotone = $this->bootstrapWithQueues($projection);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($refusal);

        $ecotone->run($channel, ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
    }

    #[DataProvider('projections_run_asynchronously')]
    public function test_consumer_of_the_batch_reports_of_a_projection_run_is_refused_to_consume_on_the_coroutines_option_of_the_console_command(object $projection, string $channel, string $refusal): void
    {
        $ecotone = $this->bootstrapWithQueues($projection);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($refusal);

        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => $channel, 'finishWhenNoMessages' => true, 'coroutines' => 3]);
    }

    #[DataProvider('projections_run_asynchronously')]
    public function test_consumer_of_the_batch_reports_of_a_projection_run_with_coroutines_in_its_polling_metadata_is_refused_when_the_application_boots(object $projection, string $channel, string $refusal): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($refusal);

        $this->bootstrapWithQueues($projection, PollingMetadata::create($channel)->withCoroutines(3));
    }

    public function test_consumer_of_the_batches_boots_with_coroutines_beside_the_consumer_of_their_batch_reports(): void
    {
        $projection = self::projections_run_asynchronously()['rebuild with a channel for its batch reports'][0];

        $ecotone = $this->bootstrapWithQueues($projection, PollingMetadata::create('rebuild_channel')->withCoroutines(3));

        $this->assertSame([], $ecotone->getGateway(GatheredGroups::class)->listOpenGroups('orders.rebuild'));
    }

    public static function projections_run_asynchronously(): array
    {
        $lostWhenHandledAtOnce = "batches finishing together would be recorded in their run at the same time, all but one would lose the run's optimistic write on every attempt until the instant retries run out, and a run missing those batches never completes. "
            . 'Leave out the `coroutines` option of ecotone:run, ExecutionPollingMetadata::withCoroutines() and PollingMetadata::withCoroutines() for this consumer. '
            . 'To handle more messages at once, ';

        return [
            'rebuild' => [
                new #[Projection('orders'), FromStream('orders_stream'), ProjectionRebuild(asyncChannelName: 'rebuild_channel')] class () {
                    #[EventHandler('order.placed')]
                    public function whenPlaced(array $event): void
                    {
                    }
                },
                'rebuild_channel',
                'Consumer `rebuild_channel` is set to consume on 3 coroutines, but it handles the batch reports of the rebuild of projection `orders` (endpoint `orders.rebuild`): ' . $lostWhenHandledAtOnce
                    . 'name a channel of their own for the batch reports as batchCompletionChannelName of #[ProjectionRebuild], consume that channel one message at a time, and consume `rebuild_channel`, which the batches are executed on, on coroutines.',
            ],
            'backfill' => [
                new #[Projection('orders'), FromStream('orders_stream'), ProjectionBackfill(asyncChannelName: 'backfill_channel')] class () {
                    #[EventHandler('order.placed')]
                    public function whenPlaced(array $event): void
                    {
                    }
                },
                'backfill_channel',
                'Consumer `backfill_channel` is set to consume on 3 coroutines, but it handles the batch reports of the backfill of projection `orders` (endpoint `orders.backfill`): ' . $lostWhenHandledAtOnce
                    . 'name a channel of their own for the batch reports as batchCompletionChannelName of #[ProjectionBackfill], consume that channel one message at a time, and consume `backfill_channel`, which the batches are executed on, on coroutines.',
            ],
            'rebuild with a channel for its batch reports' => [
                new #[Projection('orders'), FromStream('orders_stream'), ProjectionRebuild(asyncChannelName: 'rebuild_channel', batchCompletionChannelName: 'batch_completion_channel')] class () {
                    #[EventHandler('order.placed')]
                    public function whenPlaced(array $event): void
                    {
                    }
                },
                'batch_completion_channel',
                'Consumer `batch_completion_channel` is set to consume on 3 coroutines, but it handles the batch reports of the rebuild of projection `orders` (endpoint `orders.rebuild`): ' . $lostWhenHandledAtOnce
                    . 'consume `rebuild_channel`, which the batches are executed on, on coroutines.',
            ],
        ];
    }

    private function bootstrapWithQueues(object $projection, object ...$extensionObjects): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [],
            [$projection],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('rebuild_channel'),
                SimpleMessageChannelBuilder::createQueueChannel('backfill_channel'),
                SimpleMessageChannelBuilder::createQueueChannel('batch_completion_channel'),
                ...$extensionObjects,
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
