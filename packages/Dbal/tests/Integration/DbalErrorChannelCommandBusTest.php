<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Recoverability\DeadLetterGateway;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\MessagingGatewayModule;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Modelling\Attribute\InstantRetry;
use Ecotone\Test\LicenceTesting;
use Ecotone\Test\StubLogger;
use Enqueue\Dbal\DbalConnectionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\ErrorConfigurationContext;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\SynchronousErrorChannelCommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\SynchronousOrderService;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply\SynchronousInstantRetryWithAsyncChannelCommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply\SynchronousRetryWithAsyncChannelCommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply\SynchronousRetryWithAsyncRetryCommandBus;

/**
 * licence Enterprise
 * @internal
 */
#[CoversClass(ErrorChannel::class)]
#[CoversClass(InstantRetry::class)]
#[CoversClass(MessagingGatewayModule::class)]
final class DbalErrorChannelCommandBusTest extends DbalMessagingTestCase
{
    public function test_exception_handling_with_using_error_channel_right_away(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample',
        ], [new SynchronousOrderService(1)]);

        $commandBus = $ecotone->getGateway(SynchronousErrorChannelCommandBus::class);

        $commandBus->sendWithRouting('order.place', 'coffee');

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $this->assertErrorMessageCount($ecotone, 1);

        $this->replyAllErrorMessages($ecotone);

        $this->assertErrorMessageCount($ecotone, 0);

        self::assertEquals(1, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }

    public function test_exception_handling_replaying_multiple_dead_messages(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample',
        ], [new SynchronousOrderService(2)]);

        $commandBus = $ecotone->getGateway(SynchronousErrorChannelCommandBus::class);

        $commandBus->sendWithRouting('order.place', 'coffee');
        $commandBus->sendWithRouting('order.place', 'tea');

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $this->assertErrorMessageCount($ecotone, 2);

        $this->replyAllErrorMessagesById($ecotone);

        $this->assertErrorMessageCount($ecotone, 0);
        self::assertEquals(2, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }

    public function test_exception_handling_deleting_multiple_dead_messages(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample',
        ], [new SynchronousOrderService(2)]);

        $commandBus = $ecotone->getGateway(SynchronousErrorChannelCommandBus::class);

        $commandBus->sendWithRouting('order.place', 'coffee');
        $commandBus->sendWithRouting('order.place', 'tea');

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $this->assertErrorMessageCount($ecotone, 2);

        $this->deleteAllErrorMessagesById($ecotone);

        $this->assertErrorMessageCount($ecotone, 0);
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }

    public function test_passing_message_directly_to_async_channel_on_failure_and_then_succeeding(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply',
        ], [new SynchronousOrderService(1)]);

        $commandBus = $ecotone->getGateway(SynchronousRetryWithAsyncChannelCommandBus::class);

        $commandBus->sendWithRouting('order.place', 'coffee');

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $this->assertErrorMessageCount($ecotone, 0);

        $pollingMetadata = ExecutionPollingMetadata::createWithTestingSetup(failAtError: false);

        $ecotone->run(ErrorConfigurationContext::ASYNC_REPLY_CHANNEL, $pollingMetadata);
        self::assertEquals(1, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }

    public function test_recovering_with_instant_retry_before_reaching_dead_letter(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply',
        ], [new SynchronousOrderService(1)]);

        $commandBus = $ecotone->getGateway(SynchronousInstantRetryWithAsyncChannelCommandBus::class);

        $commandBus->sendWithRouting('order.place', 'coffee');

        self::assertEquals(1, $ecotone->sendQueryWithRouting('getOrderAmount'));
        $this->assertErrorMessageCount($ecotone, 0);
    }

    public function test_failing_with_instant_retry_and_reaching_dead_letter(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply',
        ], [new SynchronousOrderService(2)]);

        $commandBus = $ecotone->getGateway(SynchronousInstantRetryWithAsyncChannelCommandBus::class);

        $commandBus->sendWithRouting('order.place', 'coffee');

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        $this->assertErrorMessageCount($ecotone, 1);
    }

    public function test_passing_message_directly_to_async_channel_on_failure_and_then_succeeding_after_retry(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply',
        ], [new SynchronousOrderService(2)]);

        $commandBus = $ecotone->getGateway(SynchronousRetryWithAsyncChannelCommandBus::class);

        $commandBus->sendWithRouting('order.place', 'coffee');

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $this->assertErrorMessageCount($ecotone, 0);

        $pollingMetadata = ExecutionPollingMetadata::createWithTestingSetup(failAtError: false);

        $ecotone->run(ErrorConfigurationContext::ASYNC_REPLY_CHANNEL, $pollingMetadata);
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $ecotone->run(ErrorConfigurationContext::ASYNC_REPLY_CHANNEL, $pollingMetadata);
        self::assertEquals(1, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }

    public function test_it_fails_on_using_asynchronous_retry_on_synchronous_dead_letter(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply',
        ], [
            new SynchronousOrderService(1),
            'logger' => $logger = StubLogger::create(),
        ]);

        $commandBus = $ecotone->getGateway(SynchronousRetryWithAsyncRetryCommandBus::class);

        $exception = false;
        try {
            $commandBus->sendWithRouting('order.place', 'coffee');
        } catch (InvalidArgumentException) {
            $exception = true;
        }

        self::assertContains(
            'Failed to handle Error Message via your Retry Configuration, as it does not contain information about origination channel from which it was polled.
                    This means that most likely Synchronous Dead Letter is configured with Retry Configuration which works only for Asynchronous configuration.',
            $logger->getError()
        );
        $this->assertTrue($exception);
    }

    public function test_passing_message_directly_to_async_channel_on_failure_and_then_failing(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply',
        ], [new SynchronousOrderService(2)]);

        $commandBus = $ecotone->getGateway(SynchronousRetryWithAsyncChannelCommandBus::class);

        $commandBus->sendWithRouting('order.place', 'coffee');

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        $this->assertErrorMessageCount($ecotone, 0);

        $ecotone->run(ErrorConfigurationContext::ASYNC_REPLY_CHANNEL);
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $ecotone->run(ErrorConfigurationContext::ASYNC_REPLY_CHANNEL);
        self::assertEquals(1, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }

    private function assertErrorMessageCount(FlowTestSupport $ecotone, int $amount): void
    {
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        self::assertCount($amount, $gateway->list(100, 0));
        self::assertEquals($amount, $gateway->count());
    }

    private function deleteAllErrorMessagesById(FlowTestSupport $ecotone): void
    {
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        $gateway->delete(array_map(fn (ErrorContext $errorContext) => $errorContext->getMessageId(), $gateway->list(100, 0)));
    }

    private function replyAllErrorMessagesById(FlowTestSupport $ecotone): void
    {
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        $gateway->reply(array_map(fn (ErrorContext $errorContext) => $errorContext->getMessageId(), $gateway->list(100, 0)));
    }

    private function replyAllErrorMessages(FlowTestSupport $ecotone): void
    {
        $ecotone->getGateway(DeadLetterGateway::class)->replyAll();
    }

    private function bootstrapEcotone(array $namespaces, array $services = []): FlowTestSupport
    {
        $connectionFactory = $this->getConnectionFactory();

        return (EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge($services, [
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ]),
            configuration: ServiceConfiguration::createWithAsynchronicityOnly()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces($namespaces),
            pathToRootCatalog: __DIR__ . '/../../',
            licenceKey: LicenceTesting::VALID_LICENCE,
        ));
    }
}
