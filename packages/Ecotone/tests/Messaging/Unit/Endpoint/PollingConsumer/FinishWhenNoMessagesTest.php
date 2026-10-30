<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Endpoint\PollingConsumer;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\OnConsumerStop;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class FinishWhenNoMessagesTest extends TestCase
{
    public function test_an_idle_run_stops_on_its_first_empty_receive(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->run('async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['stopped at 00:00:00.000'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    public function test_a_run_stops_on_the_first_empty_receive_after_the_last_message(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->sendCommandWithRouting('register', 'first');
        $ecotone->run('async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['first', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    public function test_finish_when_no_messages_keeps_the_handled_message_limit(): void
    {
        $ecotone = $this->bootstrapWithThreeMessages();

        $ecotone->run('async', ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(2)->withFinishWhenNoMessages(true));

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    public function test_finish_when_no_messages_keeps_the_execution_amount_limit(): void
    {
        $ecotone = $this->bootstrapWithThreeMessages();

        $ecotone->run('async', ExecutionPollingMetadata::createWithDefaults()->withExecutionAmountLimit(3)->withFinishWhenNoMessages(true));

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    public function test_finish_when_no_messages_keeps_the_execution_time_limit(): void
    {
        $ecotone = $this->bootstrapWithThreeMessages();

        $ecotone->run('async', ExecutionPollingMetadata::createWithDefaults()->withExecutionTimeLimitInMilliseconds(1)->withFinishWhenNoMessages(true));

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.002'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    public function test_finish_when_no_messages_stops_a_run_before_any_limit_is_reached(): void
    {
        $ecotone = $this->bootstrapWithThreeMessages();

        $ecotone->run('async', ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(10)->withExecutionAmountLimit(10)->withExecutionTimeLimitInMilliseconds(60000)->withFinishWhenNoMessages(true));

        $this->assertSame(['first', 'second', 'third', 'stopped at 00:00:00.003'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    public function test_polling_metadata_of_the_endpoint_keeps_its_limits_with_finish_when_no_messages(): void
    {
        $ecotone = $this->bootstrapWithThreeMessages(PollingMetadata::create('async')->withHandledMessageLimit(2)->withFinishWhenNoMessages(true));

        $ecotone->run('async');

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    public function test_finish_when_no_messages_for_tests_drains_a_channel_whose_endpoint_has_an_execution_amount_limit(): void
    {
        $ecotone = $this->bootstrapWithThreeMessages(PollingMetadata::create('async')->withExecutionAmountLimit(2));

        $ecotone->run('async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['first', 'second', 'third', 'stopped at 00:00:00.003'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    public function test_console_run_with_finish_when_no_messages_keeps_the_handled_message_limit(): void
    {
        $ecotone = $this->bootstrapWithThreeMessages();

        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'async', 'finishWhenNoMessages' => true, 'handledMessageLimit' => 2]);

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    public function test_console_run_with_finish_when_no_messages_keeps_the_execution_time_limit(): void
    {
        $ecotone = $this->bootstrapWithThreeMessages();

        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'async', 'finishWhenNoMessages' => true, 'executionTimeLimit' => 1]);

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.002'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    public function test_console_run_of_an_idle_channel_with_finish_when_no_messages_stops_on_its_first_empty_receive(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'async', 'finishWhenNoMessages' => true, 'executionTimeLimit' => 2000]);

        $this->assertSame(['stopped at 00:00:00.000'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    private function bootstrapWithThreeMessages(?PollingMetadata $pollingMetadata = null): FlowTestSupport
    {
        $ecotone = $this->bootstrap($pollingMetadata);
        $ecotone->sendCommandWithRouting('register', 'first');
        $ecotone->sendCommandWithRouting('register', 'second');
        $ecotone->sendCommandWithRouting('register', 'third');

        return $ecotone;
    }

    private function bootstrap(?PollingMetadata $pollingMetadata = null): FlowTestSupport
    {
        $consumer = new class () {
            private array $log = [];

            #[Asynchronous('async')]
            #[CommandHandler('register', endpointId: 'register.endpoint')]
            public function register(string $name): void
            {
                $this->log[] = $name;
            }

            #[OnConsumerStop]
            public function stopped(string $endpointId, #[Reference] EcotoneClockInterface $clock): void
            {
                $this->log[] = 'stopped at ' . $clock->now()->format('H:i:s.v');
            }

            #[QueryHandler('consumer.log')]
            public function log(): array
            {
                return $this->log;
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$consumer::class],
            [$consumer],
            extensionObjects: array_filter([SimpleMessageChannelBuilder::createQueueChannel('async'), $pollingMetadata]),
            enabledModules: [],
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-01-01 00:00:00')),
        );
    }
}
