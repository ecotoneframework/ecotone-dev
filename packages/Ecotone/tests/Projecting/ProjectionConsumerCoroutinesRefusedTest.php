<?php

declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Polling;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\Streaming;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
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
final class ProjectionConsumerCoroutinesRefusedTest extends TestCase
{
    private const POLLING_PROJECTION_REFUSAL = 'Endpoint `orders_poller` is set to run on 3 coroutines, but it runs on a schedule instead of consuming a channel, so every coroutine would run each scheduled execution again. '
        . 'Leave out the `coroutines` option of ecotone:run, ExecutionPollingMetadata::withCoroutines() and PollingMetadata::withCoroutines() for this endpoint. '
        . 'To do its work on coroutines, let it send messages to an #[Asynchronous] handler and run that channel\'s consumer on coroutines.';
    private const STREAMING_PROJECTION_REFUSAL = 'Consumer `orders` is set to consume on 3 coroutines, but it reads the stream `orders_stream_channel` (a Kafka topic, a RabbitMQ stream or an in-memory streaming channel, directly or through a dynamic channel; a dynamic channel with a custom receiving strategy counts as reading every stream channel of the application), where the position of the last handled message is what gets committed. '
        . 'A coroutine finishing a later message would commit past an earlier message another coroutine is still handling, so that message is never handled when the process stops, and the messages are handled out of order.';

    public function test_polling_projection_is_refused_to_run_on_coroutines_passed_when_running_it(): void
    {
        $ecotone = $this->bootstrap($this->pollingProjection());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::POLLING_PROJECTION_REFUSAL);

        $ecotone->run('orders_poller', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
    }

    public function test_polling_projection_with_coroutines_in_its_polling_metadata_is_refused_when_the_application_boots(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::POLLING_PROJECTION_REFUSAL);

        $this->bootstrap($this->pollingProjection(), PollingMetadata::create('orders_poller')->withCoroutines(3));
    }

    public function test_streaming_projection_is_refused_to_consume_on_coroutines_passed_when_running_it(): void
    {
        $ecotone = $this->bootstrap($this->streamingProjection());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::STREAMING_PROJECTION_REFUSAL);

        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
    }

    public function test_streaming_projection_with_coroutines_in_its_polling_metadata_is_refused_when_the_application_boots(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::STREAMING_PROJECTION_REFUSAL);

        $this->bootstrap($this->streamingProjection(), PollingMetadata::create('orders')->withCoroutines(3));
    }

    private function pollingProjection(): object
    {
        return new #[Projection('orders'), FromStream('orders_stream'), Polling('orders_poller')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };
    }

    private function streamingProjection(): object
    {
        return new #[Projection('orders'), Streaming('orders_stream_channel')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };
    }

    private function bootstrap(object $projection, object ...$extensionObjects): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [],
            [$projection],
            extensionObjects: [SimpleMessageChannelBuilder::createStreamingChannel('orders_stream_channel'), ...$extensionObjects],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
