<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Recoverability;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Support\MessageBuilder;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class InMemoryStorageInFlowTestsTest extends DbalMessagingTestCase
{
    public function test_an_explicit_dbal_configuration_keeps_dead_letters_in_the_database(): void
    {
        $first = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()]),
        )->setupDatabaseTables();
        $second = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()]),
        );

        $first->getGateway(DeadLetterGateway::class)->store($this->failedMessage('m-1'));

        self::assertSame(1, $second->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_an_explicit_dbal_configuration_deduplicates_in_the_database(): void
    {
        $first = $this->bootstrapWithDeduplicatedHandler(DbalConfiguration::createWithDefaults());
        $first->setupDatabaseTables();
        $second = $this->bootstrapWithDeduplicatedHandler(DbalConfiguration::createWithDefaults());

        $first->sendCommandWithRouting('inMemoryStorage.order', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1']);
        $second->sendCommandWithRouting('inMemoryStorage.order', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1']);

        self::assertSame(['coffee'], $first->sendQueryWithRouting('inMemoryStorage.orders'));
        self::assertSame([], $second->sendQueryWithRouting('inMemoryStorage.orders'));
    }

    public function test_an_application_outside_flow_testing_keeps_dead_letters_in_the_database_by_default(): void
    {
        $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()]),
        )->setupDatabaseTables();
        $application = new class () {
        };
        $first = EcotoneLite::bootstrap(
            classesToResolve: [$application::class],
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE])
                ->withNamespaces([]),
            pathToRootCatalog: __DIR__ . '/../../../',
        );
        $second = EcotoneLite::bootstrap(
            classesToResolve: [$application::class],
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE])
                ->withNamespaces([]),
            pathToRootCatalog: __DIR__ . '/../../../',
        );

        $first->getGatewayByName(DeadLetterGateway::class)->store($this->failedMessage('m-1'));

        self::assertSame(1, $second->getGatewayByName(DeadLetterGateway::class)->count());
    }

    private function bootstrapWithDeduplicatedHandler(?DbalConfiguration $dbalConfiguration): FlowTestSupport
    {
        $handler = new class () {
            private array $orders = [];

            #[Deduplicated]
            #[CommandHandler('inMemoryStorage.order', endpointId: 'inMemoryStorage.order.endpoint')]
            public function order(string $order): void
            {
                $this->orders[] = $order;
            }

            #[QueryHandler('inMemoryStorage.orders')]
            public function orders(): array
            {
                return $this->orders;
            }
        };

        return $this->bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects($dbalConfiguration === null ? [] : [$dbalConfiguration]),
        );
    }

    private function failedMessage(string $messageId): Message
    {
        return MessageBuilder::withPayload('order coffee')
            ->setMultipleHeaders([
                MessageHeaders::MESSAGE_ID => $messageId,
                ErrorContext::EXCEPTION_MESSAGE => 'failed',
                ErrorContext::EXCEPTION_STACKTRACE => '#0 handler.php(1)',
                ErrorContext::EXCEPTION_FILE => 'handler.php',
                ErrorContext::EXCEPTION_LINE => 1,
                ErrorContext::EXCEPTION_CODE => 0,
            ])
            ->build();
    }
}
