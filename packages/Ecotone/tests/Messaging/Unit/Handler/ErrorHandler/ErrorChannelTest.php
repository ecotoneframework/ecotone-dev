<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\ErrorHandler;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\Handler\ErrorChannel\FailingScheduledExample;
use Test\Ecotone\Messaging\Fixture\Handler\ErrorChannelAsync\AsyncFailingHandler;
use Test\Ecotone\Messaging\Fixture\Handler\ErrorChannelAsync\DelayedRetryHandler;
use Test\Ecotone\Messaging\Fixture\Handler\ErrorChannel\OrderService;
use Test\Ecotone\Messaging\Fixture\Handler\ErrorChannelAsyncMisplaced\AsyncHandlerWithDelayedRetryDirectlyOnMethod;
use Test\Ecotone\Messaging\Fixture\Handler\ErrorChannelAsyncMisplaced\AsyncHandlerWithErrorChannelDirectlyOnMethod;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class ErrorChannelTest extends TestCase
{
    public function test_exception_handling_with_retries_without_dead_letter_uses_final_failure_strategy(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\Messaging\Fixture\Handler\ErrorChannel']),
            pathToRootCatalog: __DIR__ . '/../../../../',
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('correctOrders', finalFailureStrategy: FinalFailureStrategy::RESEND),
            ]
        );

        $ecotone
            ->sendCommandWithRoutingKey('order.register', 'coffee')
            ->run('correctOrders', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))
        ;

        // First attempt fails, message is sent to error channel for delayed retry
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        // Second attempt (first delayed retry) - still fails
        $ecotone
            ->run('correctOrders', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))
        ;

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        // Third attempt (second delayed retry)
        $ecotone
            ->run('correctOrders', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))
        ;

        $this->assertSame(3, $ecotone->sendQueryWithRouting('getCallCount'));

        $this->assertSame(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        $ecotone
            ->run('correctOrders', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))
        ;
        $this->assertSame(1, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }


    public function test_exception_handling_with_retries_without_dead_letter_uses_final_failure_strategy_with_ignore(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\Messaging\Fixture\Handler\ErrorChannel']),
            pathToRootCatalog: __DIR__ . '/../../../../',
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('correctOrders', finalFailureStrategy: FinalFailureStrategy::IGNORE),
            ]
        );

        $ecotone
            ->sendCommandWithRoutingKey('order.register', 'coffee')
            ->run('correctOrders', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))
        ;

        // First attempt fails, message is sent to error channel for delayed retry
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        // Second attempt (first delayed retry) - still fails
        $ecotone
            ->run('correctOrders', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))
        ;

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        // Third attempt (second delayed retry)
        $ecotone
            ->run('correctOrders', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))
        ;

        $this->assertSame(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        $ecotone
            ->run('correctOrders', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))
        ;
        $this->assertSame(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }

    public function test_using_custom_channel_for_error_handling(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class],
            [new OrderService()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    ErrorHandlerConfiguration::create(
                        $errorChannelName = 'failureOrders',
                        RetryTemplateBuilder::exponentialBackoff(1, 1)
                            ->maxRetryAttempts(2)
                    ),
                ])
                ->withDefaultErrorChannel($errorChannelName),
            pathToRootCatalog: __DIR__ . '/../../../../',
            enableAsynchronousProcessing: [
                SimpleMessageChannelBuilder::createQueueChannel('correctOrders', finalFailureStrategy: FinalFailureStrategy::IGNORE),
                SimpleMessageChannelBuilder::createQueueChannel($errorChannelName, finalFailureStrategy: FinalFailureStrategy::RESEND),
            ]
        );

        $ecotone
            ->sendCommandWithRoutingKey('order.register', 'coffee')
            ->run('correctOrders', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))
        ;

        // First attempt fails, message is sent to error channel for delayed retry
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        // Second attempt (first delayed retry) - still fails
        $ecotone
            ->run($errorChannelName, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))
        ;

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        // Third attempt (second delayed retry)
        $ecotone
            ->run($errorChannelName, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false))
        ;

        $this->assertSame(3, $ecotone->sendQueryWithRouting('getCallCount'));

        $this->assertSame(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        $ecotone
            ->run($errorChannelName, ExecutionPollingMetadata::createWithTestingSetup(failAtError: true))
        ;
        $this->assertSame(1, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }

    public function test_inbound_channel_adapter_sends_failed_message_to_default_error_channel_using_routing_slip(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [FailingScheduledExample::class],
            [new FailingScheduledExample()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withDefaultErrorChannel('customErrorChannel')
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('customErrorChannel'),
                ]),
        );

        $ecotone->run(FailingScheduledExample::ENDPOINT_ID, ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            failAtError: false,
        ));

        /** @var PollableChannel $errorChannel */
        $errorChannel = $ecotone->getMessageChannel('customErrorChannel');
        $errorMessage = $errorChannel->receive();
        $this->assertNotNull($errorMessage, 'Expected failed message to be delivered to default error channel');

        $headers = $errorMessage->getHeaders();
        $this->assertFalse(
            $headers->containsKey(MessageHeaders::POLLED_CHANNEL_NAME),
            'Inbound Channel Adapter has no source pollable Message Channel; POLLED_CHANNEL_NAME must not be set'
        );
        $this->assertTrue(
            $headers->containsKey(MessageHeaders::ROUTING_SLIP),
            'Routing slip is required for replay back to an Inbound Channel Adapter consumer (Kafka, AMQP inbound, #[Scheduled], etc.)'
        );
        $this->assertSame(FailingScheduledExample::REQUEST_CHANNEL, $headers->get(MessageHeaders::ROUTING_SLIP));
    }

    public function test_inbound_channel_adapter_with_delayed_retry_template_throws_clear_error_about_missing_polled_channel(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [FailingScheduledExample::class],
            [new FailingScheduledExample()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withDefaultErrorChannel('retryErrorChannel')
                ->withExtensionObjects([
                    ErrorHandlerConfiguration::create(
                        'retryErrorChannel',
                        RetryTemplateBuilder::exponentialBackoff(1, 1)->maxRetryAttempts(2)
                    ),
                ]),
        );

        $this->expectException(\Ecotone\Messaging\Handler\MessageHandlingException::class);
        $this->expectExceptionMessage('does not contain information about origination channel from which it was polled');

        $ecotone->run(FailingScheduledExample::ENDPOINT_ID, ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            failAtError: false,
        ));
    }

    public function test_async_handler_routes_failure_to_error_channel_declared_via_endpoint_annotations(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [AsyncFailingHandler::class],
            [new AsyncFailingHandler()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(AsyncFailingHandler::SHARED_ASYNC_CHANNEL),
                    SimpleMessageChannelBuilder::createQueueChannel(AsyncFailingHandler::ERROR_CHANNEL_A),
                    SimpleMessageChannelBuilder::createQueueChannel(AsyncFailingHandler::ERROR_CHANNEL_B),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRoutingKey(AsyncFailingHandler::ROUTING_KEY_A, 'payload-a');
        $ecotone->run(AsyncFailingHandler::SHARED_ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            failAtError: false,
        ));

        /** @var PollableChannel $errorChannelA */
        $errorChannelA = $ecotone->getMessageChannel(AsyncFailingHandler::ERROR_CHANNEL_A);
        /** @var PollableChannel $errorChannelB */
        $errorChannelB = $ecotone->getMessageChannel(AsyncFailingHandler::ERROR_CHANNEL_B);

        $this->assertNotNull($errorChannelA->receive(), 'Handler A failure must be routed to its declared error channel');
        $this->assertNull($errorChannelB->receive(), 'Handler B error channel must remain empty when only handler A failed');
    }

    public function test_two_async_handlers_sharing_channel_each_route_failures_to_their_own_error_channel(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [AsyncFailingHandler::class],
            [new AsyncFailingHandler()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(AsyncFailingHandler::SHARED_ASYNC_CHANNEL),
                    SimpleMessageChannelBuilder::createQueueChannel(AsyncFailingHandler::ERROR_CHANNEL_A),
                    SimpleMessageChannelBuilder::createQueueChannel(AsyncFailingHandler::ERROR_CHANNEL_B),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRoutingKey(AsyncFailingHandler::ROUTING_KEY_A, 'payload-a');
        $ecotone->sendCommandWithRoutingKey(AsyncFailingHandler::ROUTING_KEY_B, 'payload-b');

        $ecotone->run(AsyncFailingHandler::SHARED_ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 2,
            failAtError: false,
        ));

        /** @var PollableChannel $errorChannelA */
        $errorChannelA = $ecotone->getMessageChannel(AsyncFailingHandler::ERROR_CHANNEL_A);
        /** @var PollableChannel $errorChannelB */
        $errorChannelB = $ecotone->getMessageChannel(AsyncFailingHandler::ERROR_CHANNEL_B);

        $messageInA = $errorChannelA->receive();
        $messageInB = $errorChannelB->receive();

        $this->assertNotNull($messageInA, 'Handler A failure must land in error channel A');
        $this->assertNotNull($messageInB, 'Handler B failure must land in error channel B');
        $this->assertNull($errorChannelA->receive(), 'Only one message expected in error channel A');
        $this->assertNull($errorChannelB->receive(), 'Only one message expected in error channel B');
    }

    public function test_async_handler_endpoint_annotation_error_channel_overrides_default_error_channel(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [AsyncFailingHandler::class],
            [new AsyncFailingHandler()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withDefaultErrorChannel('globalDefaultErrorChannel')
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(AsyncFailingHandler::SHARED_ASYNC_CHANNEL),
                    SimpleMessageChannelBuilder::createQueueChannel(AsyncFailingHandler::ERROR_CHANNEL_A),
                    SimpleMessageChannelBuilder::createQueueChannel('globalDefaultErrorChannel'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRoutingKey(AsyncFailingHandler::ROUTING_KEY_A, 'payload-a');
        $ecotone->run(AsyncFailingHandler::SHARED_ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            failAtError: false,
        ));

        /** @var PollableChannel $globalDefault */
        $globalDefault = $ecotone->getMessageChannel('globalDefaultErrorChannel');
        /** @var PollableChannel $errorChannelA */
        $errorChannelA = $ecotone->getMessageChannel(AsyncFailingHandler::ERROR_CHANNEL_A);

        $this->assertNotNull($errorChannelA->receive(), 'Per-handler #[ErrorChannel] must override the default error channel');
        $this->assertNull($globalDefault->receive(), 'Default error channel must not receive the failure when handler declares its own');
    }

    public function test_retry_policy_retries_handler_until_success(): void
    {
        $handler = new DelayedRetryHandler();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [DelayedRetryHandler::class],
            [$handler],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(DelayedRetryHandler::ASYNC_CHANNEL),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRoutingKey(DelayedRetryHandler::ROUTING_KEY_RECOVERS, 'payload');

        $ecotone->run(DelayedRetryHandler::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            failAtError: false,
        ));
        $this->assertSame(1, $ecotone->sendQueryWithRouting('retryHandler.attemptsRecovers'));
        $this->assertFalse($ecotone->sendQueryWithRouting('retryHandler.finallyHandled'));

        $ecotone->run(DelayedRetryHandler::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            failAtError: false,
        ));
        $this->assertSame(2, $ecotone->sendQueryWithRouting('retryHandler.attemptsRecovers'));
        $this->assertTrue($ecotone->sendQueryWithRouting('retryHandler.finallyHandled'));
    }

    public function test_retry_policy_routes_to_dead_letter_when_retries_exhausted(): void
    {
        $handler = new DelayedRetryHandler();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [DelayedRetryHandler::class],
            [$handler],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(DelayedRetryHandler::ASYNC_CHANNEL),
                    SimpleMessageChannelBuilder::createQueueChannel(DelayedRetryHandler::DEAD_LETTER_CHANNEL),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRoutingKey(DelayedRetryHandler::ROUTING_KEY_DEAD_LETTER, 'payload');

        for ($i = 0; $i < 3; $i++) {
            $ecotone->run(DelayedRetryHandler::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(
                amountOfMessagesToHandle: 1,
                failAtError: false,
            ));
        }

        $this->assertSame(3, $ecotone->sendQueryWithRouting('retryHandler.attemptsDeadLetter'), 'Handler invoked maxAttempts+1 times before exhaustion');

        /** @var PollableChannel $deadLetter */
        $deadLetter = $ecotone->getMessageChannel(DelayedRetryHandler::DEAD_LETTER_CHANNEL);
        $this->assertNotNull($deadLetter->receive(), 'Failed message must land in the dead letter channel after retries are exhausted');
        $this->assertNull($deadLetter->receive(), 'Only one failed message expected in the dead letter channel');
    }

    public function test_retry_policy_overrides_global_default_error_channel(): void
    {
        $handler = new DelayedRetryHandler();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [DelayedRetryHandler::class],
            [$handler],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withDefaultErrorChannel('globalDefaultErrorChannel')
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(DelayedRetryHandler::ASYNC_CHANNEL),
                    SimpleMessageChannelBuilder::createQueueChannel(DelayedRetryHandler::DEAD_LETTER_CHANNEL),
                    SimpleMessageChannelBuilder::createQueueChannel('globalDefaultErrorChannel'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRoutingKey(DelayedRetryHandler::ROUTING_KEY_OVERRIDE, 'payload');

        for ($i = 0; $i < 2; $i++) {
            $ecotone->run(DelayedRetryHandler::ASYNC_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(
                amountOfMessagesToHandle: 1,
                failAtError: false,
            ));
        }

        $this->assertSame(2, $ecotone->sendQueryWithRouting('retryHandler.attemptsOverride'));

        /** @var PollableChannel $deadLetter */
        $deadLetter = $ecotone->getMessageChannel(DelayedRetryHandler::DEAD_LETTER_CHANNEL);
        /** @var PollableChannel $globalDefault */
        $globalDefault = $ecotone->getMessageChannel('globalDefaultErrorChannel');

        $this->assertNotNull($deadLetter->receive(), '#[DelayedRetry] must route the failure to its own dead letter channel');
        $this->assertNull($globalDefault->receive(), 'Global default error channel must not receive the failure when handler declares #[DelayedRetry]');
    }

    public function test_async_handler_with_error_channel_directly_on_method_throws_descriptive_error(): void
    {
        $this->expectException(\Ecotone\Messaging\Config\ConfigurationException::class);
        $this->expectExceptionMessage('#[ErrorChannel]');
        $this->expectExceptionMessage('asynchronousExecution');

        EcotoneLite::bootstrapFlowTesting(
            [AsyncHandlerWithErrorChannelDirectlyOnMethod::class],
            [new AsyncHandlerWithErrorChannelDirectlyOnMethod()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(AsyncHandlerWithErrorChannelDirectlyOnMethod::ASYNC_CHANNEL),
                    SimpleMessageChannelBuilder::createQueueChannel('someErrorChannel'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_async_handler_with_delayed_retry_directly_on_method_throws_descriptive_error(): void
    {
        $this->expectException(\Ecotone\Messaging\Config\ConfigurationException::class);
        $this->expectExceptionMessage('#[DelayedRetry]');
        $this->expectExceptionMessage('asynchronousExecution');

        EcotoneLite::bootstrapFlowTesting(
            [AsyncHandlerWithDelayedRetryDirectlyOnMethod::class],
            [new AsyncHandlerWithDelayedRetryDirectlyOnMethod()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel(AsyncHandlerWithDelayedRetryDirectlyOnMethod::ASYNC_CHANNEL),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
