<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Dbal\Recoverability\DeadLetterGateway;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\Dbal\DbalConnectionFactory;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DeadLetter\DoubleEventHandler\DoubleEventHandler;
use Test\Ecotone\Dbal\Fixture\DeadLetter\DoubleEventHandler\ExampleEvent;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\ErrorConfigurationContext;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\OrderGateway;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\OrderService;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DeadLetterTest extends DbalMessagingTestCase
{
    public function test_exception_handling_with_using_error_channel_right_away(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\Example',
        ], extensionObjects: [
            PollingMetadata::create('orderService')
                ->setExecutionTimeLimitInMilliseconds(1000)
                ->setHandledMessageLimit(1)
                ->setErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL),
        ], orderService: new OrderService(1));

        $gateway = $ecotone->getGateway(OrderGateway::class);

        $gateway->order('coffee');

        $ecotone->run('orderService');

        self::assertEquals(0, $gateway->getOrderAmount());

        $this->assertErrorMessageCount($ecotone, 1, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $this->replyAllErrorMessages($ecotone);

        $this->assertErrorMessageCount($ecotone, 0, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $ecotone->run('orderService');

        self::assertEquals(1, $gateway->getOrderAmount());
    }

    public function test_exception_handling_with_custom_handling_1_retry(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\Example',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\CustomConfiguration',
        ]);

        $gateway = $ecotone->getGateway(OrderGateway::class);

        $gateway->order('coffee');

        $ecotone->run('orderService');

        self::assertEquals(0, $gateway->getOrderAmount());

        $ecotone->run('orderService');

        self::assertEquals(0, $gateway->getOrderAmount());

        $this->assertErrorMessageCount($ecotone, 1, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $this->replyAllErrorMessages($ecotone);

        $this->assertErrorMessageCount($ecotone, 0, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $ecotone->run('orderService');

        self::assertEquals(1, $gateway->getOrderAmount());
    }

    public function test_exception_handling_replaying_multiple_dead_messages(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\Example',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\DeadLetterRightAway',
        ]);

        $gateway = $ecotone->getGateway(OrderGateway::class);

        $gateway->order('coffee');

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        $gateway->order('coffee');

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        $this->assertErrorMessageCount($ecotone, 2, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $this->replyAllErrorMessagesById($ecotone);

        $this->assertErrorMessageCount($ecotone, 0, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        self::assertEquals(2, $gateway->getOrderAmount());
    }

    public function test_exception_handling_deleting_multiple_dead_messages(): void
    {
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\Example',
            'Test\Ecotone\Dbal\Fixture\DeadLetter\DeadLetterRightAway',
        ]);

        $gateway = $ecotone->getGateway(OrderGateway::class);

        $gateway->order('coffee');

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        $gateway->order('coffee');

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        $this->assertErrorMessageCount($ecotone, 2, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $this->deleteAllErrorMessagesById($ecotone);

        $this->assertErrorMessageCount($ecotone, 0, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        self::assertEquals(0, $gateway->getOrderAmount());
    }

    public function test_same_event_is_stored_in_dead_letter_twice_for_different_endpoints_and_replayed(): void
    {
        $doubleEventHandler = new DoubleEventHandler();
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\DoubleEventHandler',
        ], [$doubleEventHandler]);

        $ecotone->publishEvent(new ExampleEvent('test'));

        $ecotone->run('async');
        $ecotone->run('async');

        $this->assertErrorMessageCount($ecotone, 2);
        self::assertEquals(0, $doubleEventHandler->successfulCalls);

        $this->replyAllErrorMessagesById($ecotone);
        $this->assertErrorMessageCount($ecotone, 0);

        $ecotone->run('async');
        $ecotone->run('async');

        self::assertEquals(2, $doubleEventHandler->successfulCalls);
    }

    public function test_same_event_is_stored_in_dead_letter_twice_for_different_endpoints_and_removed(): void
    {
        $doubleEventHandler = new DoubleEventHandler();
        $ecotone = $this->bootstrapEcotone([
            'Test\Ecotone\Dbal\Fixture\DeadLetter\DoubleEventHandler',
        ], [$doubleEventHandler]);
        $deadLetter = $ecotone->getGateway(DeadLetterGateway::class);

        $messageId = Uuid::v7()->toRfc4122();
        $ecotone->publishEvent(new ExampleEvent('test'), metadata: [MessageHeaders::MESSAGE_ID => $messageId]);

        $ecotone->run('async');
        $ecotone->run('async');

        $this->assertErrorMessageCount($ecotone, 2);
        $deadLetter->delete($messageId);
        $this->assertErrorMessageCount($ecotone, 1);

        $messages = $deadLetter->list(100, 0);
        self::assertCount(1, $messages);
        /** As new Message Id was generated */
        self::assertNotEquals($messageId, $messages[0]->getMessageId());
        $deadLetter->delete($messages[0]->getMessageId());
        $this->assertErrorMessageCount($ecotone, 0);
    }

    private function assertErrorMessageCount(FlowTestSupport $ecotone, int $amount, string $deadLetterReference = DeadLetterGateway::class): void
    {
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        self::assertCount($amount, $gateway->list(100, 0));
        self::assertEquals($amount, $gateway->count());

        /** @var DeadLetterGateway $gateway */
        $gateway = $ecotone->getGateway($deadLetterReference);

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

    private function bootstrapEcotone(array $namespaces, array $services = [], array $extensionObjects = [], ?OrderService $orderService = null): FlowTestSupport
    {
        $connectionFactory = $this->getConnectionFactory();

        return (EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge($services, [
                $orderService ?? new OrderService(),
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects($extensionObjects)
                ->withNamespaces($namespaces),
            pathToRootCatalog: __DIR__ . '/../../',
        ));
    }
}
