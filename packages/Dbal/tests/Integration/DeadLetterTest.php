<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Recoverability\DeadLetterGateway;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\ErrorConfigurationContext;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\OrderGateway;
use Test\Ecotone\Dbal\Fixture\DeadLetter\Example\OrderService;

/**
 * @internal
 */
final class DeadLetterTest extends DbalMessagingTestCase
{
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

        $this->assertErrorMessageCount($ecotone, 1);

        $this->replyAllErrorMessages($ecotone);

        $this->assertErrorMessageCount($ecotone, 0);

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

        $this->assertErrorMessageCount($ecotone, 2);

        $this->replyAllErrorMessagesById($ecotone);

        $this->assertErrorMessageCount($ecotone, 0);

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

        $this->assertErrorMessageCount($ecotone, 2);

        $this->deleteAllErrorMessagesById($ecotone);

        $this->assertErrorMessageCount($ecotone, 0);

        $ecotone->run('orderService');
        $ecotone->run('orderService');

        self::assertEquals(0, $gateway->getOrderAmount());
    }

    private function assertErrorMessageCount(FlowTestSupport $ecotone, int $amount): void
    {
        $gateway = $ecotone->getGateway(DeadLetterGateway::class);

        self::assertCount($amount, $gateway->list(100, 0));
        self::assertEquals($amount, $gateway->count());

        /** @var DeadLetterGateway $gateway */
        $gateway = $ecotone->getGateway(ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

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

    private function bootstrapEcotone(array $namespaces): FlowTestSupport
    {
        $connectionFactory = $this->getConnectionFactory();

        return (EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new OrderService(), DbalConnectionFactory::class => $connectionFactory, 'managerRegistry' => $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces($namespaces),
            pathToRootCatalog: __DIR__ . '/../../',
        ));
    }
}
