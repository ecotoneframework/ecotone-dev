<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Dbal\Recoverability\DeadLetterGateway;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\ErrorConfigurationContext;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\SynchronousErrorChannelCommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\SynchronousOrderService;

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

        // Verify that the order was not processed successfully
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));

        // Verify that the error message was stored in the dead letter
        $this->assertErrorMessageCount($ecotone, 1, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        // Reply the error message
        $this->replyAllErrorMessages($ecotone);

        // Verify that the error message was removed from the dead letter
        $this->assertErrorMessageCount($ecotone, 0, ErrorConfigurationContext::CUSTOM_GATEWAY_REFERENCE_NAME);

        // Verify that the order was processed successfully after replying
        self::assertEquals(1, $ecotone->sendQueryWithRouting('getOrderAmount'));
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

    private function bootstrapEcotone(array $namespaces, array $services = []): FlowTestSupport
    {
        $connectionFactory = $this->getConnectionFactory();

        return (EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge($services, [
                DbalConnectionFactory::class => $connectionFactory,
                'managerRegistry' => $connectionFactory,
            ]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withNamespaces($namespaces),
            pathToRootCatalog: __DIR__ . '/../../',
            licenceKey: LicenceTesting::VALID_LICENCE,
        ));
    }
}
