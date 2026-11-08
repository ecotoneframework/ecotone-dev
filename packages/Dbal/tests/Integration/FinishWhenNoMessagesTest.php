<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\OnConsumerStop;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class FinishWhenNoMessagesTest extends DbalMessagingTestCase
{
    public static function channels(): iterable
    {
        yield 'in memory' => [false];
        yield 'dbal' => [true];
    }

    #[DataProvider('channels')]
    public function test_an_idle_run_stops_on_its_first_empty_receive(bool $onDbal): void
    {
        $ecotone = $this->bootstrap($onDbal);

        $ecotone->run('async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['stopped at 00:00:00.000'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    #[DataProvider('channels')]
    public function test_a_run_stops_on_the_first_empty_receive_after_the_last_message(bool $onDbal): void
    {
        $ecotone = $this->bootstrap($onDbal);

        $ecotone->sendCommandWithRouting('register', 'first');
        $ecotone->run('async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['first', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    #[DataProvider('channels')]
    public function test_finish_when_no_messages_keeps_the_handled_message_limit(bool $onDbal): void
    {
        $ecotone = $this->bootstrapWithThreeMessages($onDbal);

        $ecotone->run('async', ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(2)->withFinishWhenNoMessages(true));

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    #[DataProvider('channels')]
    public function test_finish_when_no_messages_keeps_the_execution_amount_limit(bool $onDbal): void
    {
        $ecotone = $this->bootstrapWithThreeMessages($onDbal);

        $ecotone->run('async', ExecutionPollingMetadata::createWithDefaults()->withExecutionAmountLimit(3)->withFinishWhenNoMessages(true));

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    #[DataProvider('channels')]
    public function test_finish_when_no_messages_keeps_the_execution_time_limit(bool $onDbal): void
    {
        $ecotone = $this->bootstrapWithThreeMessages($onDbal);

        $ecotone->run('async', ExecutionPollingMetadata::createWithDefaults()->withExecutionTimeLimitInMilliseconds(1)->withFinishWhenNoMessages(true));

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.002'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    #[DataProvider('channels')]
    public function test_finish_when_no_messages_stops_a_run_before_any_limit_is_reached(bool $onDbal): void
    {
        $ecotone = $this->bootstrapWithThreeMessages($onDbal);

        $ecotone->run('async', ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(10)->withExecutionAmountLimit(10)->withExecutionTimeLimitInMilliseconds(60000)->withFinishWhenNoMessages(true));

        $this->assertSame(['first', 'second', 'third', 'stopped at 00:00:00.003'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    #[DataProvider('channels')]
    public function test_polling_metadata_of_the_endpoint_keeps_its_handled_message_limit_with_finish_when_no_messages(bool $onDbal): void
    {
        $ecotone = $this->bootstrapWithThreeMessages($onDbal, PollingMetadata::create('async')->withHandledMessageLimit(2)->withFinishWhenNoMessages(true));

        $ecotone->run('async');

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    #[DataProvider('channels')]
    public function test_polling_metadata_of_the_endpoint_keeps_its_execution_amount_limit_with_finish_when_no_messages(bool $onDbal): void
    {
        $ecotone = $this->bootstrapWithThreeMessages($onDbal, PollingMetadata::create('async')->withExecutionAmountLimit(3)->withFinishWhenNoMessages(true));

        $ecotone->run('async');

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    #[DataProvider('channels')]
    public function test_polling_metadata_of_the_endpoint_keeps_its_execution_time_limit_with_finish_when_no_messages(bool $onDbal): void
    {
        $ecotone = $this->bootstrapWithThreeMessages($onDbal, PollingMetadata::create('async')->withExecutionTimeLimitInMilliseconds(1)->withFinishWhenNoMessages(true));

        $ecotone->run('async');

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.002'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    #[DataProvider('channels')]
    public function test_finish_when_no_messages_for_tests_drains_a_channel_whose_endpoint_has_limits(bool $onDbal): void
    {
        $ecotone = $this->bootstrapWithThreeMessages($onDbal, PollingMetadata::create('async')->withHandledMessageLimit(1)->withExecutionAmountLimit(1)->withExecutionTimeLimitInMilliseconds(1));

        $ecotone->run('async', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['first', 'second', 'third', 'stopped at 00:00:00.003'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    #[DataProvider('channels')]
    public function test_console_run_with_finish_when_no_messages_keeps_the_handled_message_limit(bool $onDbal): void
    {
        $ecotone = $this->bootstrapWithThreeMessages($onDbal);

        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'async', 'finishWhenNoMessages' => true, 'handledMessageLimit' => 2]);

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.001'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    #[DataProvider('channels')]
    public function test_console_run_with_finish_when_no_messages_keeps_the_execution_time_limit(bool $onDbal): void
    {
        $ecotone = $this->bootstrapWithThreeMessages($onDbal);

        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'async', 'finishWhenNoMessages' => true, 'executionTimeLimit' => 1]);

        $this->assertSame(['first', 'second', 'stopped at 00:00:00.002'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    #[DataProvider('channels')]
    public function test_console_run_of_an_idle_channel_with_finish_when_no_messages_stops_on_its_first_empty_receive(bool $onDbal): void
    {
        $ecotone = $this->bootstrap($onDbal);

        $ecotone->runConsoleCommand('ecotone:run', ['consumerName' => 'async', 'finishWhenNoMessages' => true, 'executionTimeLimit' => 2000]);

        $this->assertSame(['stopped at 00:00:00.000'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    private function bootstrapWithThreeMessages(bool $onDbal, ?PollingMetadata $pollingMetadata = null): FlowTestSupport
    {
        $ecotone = $this->bootstrap($onDbal, $pollingMetadata);
        $ecotone->sendCommandWithRouting('register', 'first');
        $ecotone->sendCommandWithRouting('register', 'second');
        $ecotone->sendCommandWithRouting('register', 'third');

        return $ecotone;
    }

    private function bootstrap(bool $onDbal, ?PollingMetadata $pollingMetadata = null): FlowTestSupport
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

        return $this->bootstrapFlowTesting(
            [$consumer::class],
            [$consumer, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: array_filter([$onDbal ? DbalBackedMessageChannelBuilder::create('async') : SimpleMessageChannelBuilder::createQueueChannel('async'), $pollingMetadata]),
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            initialTime: new DateTimeImmutable('2026-01-01 00:00:00'),
        );
    }
}
