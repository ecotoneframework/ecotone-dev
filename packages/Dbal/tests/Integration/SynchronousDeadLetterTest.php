<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Dbal\Recoverability\DeadLetterGateway;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\ErrorConfigurationContext;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\SynchronousErrorChannelCommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\SynchronousErrorChannelWithReplyCommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\SynchronousOrderService;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply\SynchronousRetryWithReplyCommandBus;

/**
 * licence Apache-2.0
 * @internal
 */
final class SynchronousDeadLetterTest extends DbalMessagingTestCase
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

    public function test_exception_handling_with_custom_reply_channel(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample',
        ], [new SynchronousOrderService(1)]);

        $commandBus = $ecotone->getGateway(SynchronousErrorChannelWithReplyCommandBus::class);

        $commandBus->sendWithRouting('order.place', 'coffee');

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $this->assertErrorMessageCount($ecotone, 1);

        $this->replyAllErrorMessages($ecotone);

        $this->assertErrorMessageCount($ecotone, 0);
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $ecotone->run(ErrorConfigurationContext::ASYNC_REPLY_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup());
        self::assertEquals(1, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }

    public function test_passing_message_directly_to_async_channel_on_failure_and_then_succeeding(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply',
        ], [new SynchronousOrderService(1)]);

        $commandBus = $ecotone->getGateway(SynchronousRetryWithReplyCommandBus::class);

        $commandBus->sendWithRouting('order.place', 'coffee');

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        $this->assertErrorMessageCount($ecotone, 0);

        $pollingMetadata = ExecutionPollingMetadata::createWithTestingSetup(failAtError: false);

        $ecotone->run(ErrorConfigurationContext::ASYNC_REPLY_CHANNEL, $pollingMetadata);
        self::assertEquals(1, $ecotone->sendQueryWithRouting('getOrderAmount'));
    }

    public function test_passing_message_directly_to_async_channel_on_failure_and_then_failing(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply',
        ], [new SynchronousOrderService(2)]);

        $commandBus = $ecotone->getGateway(SynchronousRetryWithReplyCommandBus::class);

        $commandBus->sendWithRouting('order.place', 'coffee');

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        $this->assertErrorMessageCount($ecotone, 0);

        $ecotone->run(ErrorConfigurationContext::ASYNC_REPLY_CHANNEL);

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        $this->assertErrorMessageCount($ecotone, 1);

        $this->replyAllErrorMessages($ecotone);
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
